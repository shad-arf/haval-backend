<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\FibPaymentAttempt;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\DolibarrOrderPusher;
use App\Services\FibCardsClient;
use App\Services\FibPaymentReconciler;
use App\Services\FibQrClient;
use App\Services\FibQrPaymentReconciler;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly FibCardsClient $fib,
        private readonly FibPaymentReconciler $reconciler,
        private readonly FibQrClient $fibQr,
        private readonly FibQrPaymentReconciler $qrReconciler,
        private readonly DolibarrOrderPusher $pusher,
        private readonly InvoicePdfRenderer $invoices,
    ) {}

    /**
     * The payment methods the storefront may offer right now.
     *
     * The card method is named for the cards it accepts, not the bank behind
     * it — customers recognise their card, not the acquirer.
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = [[
            'id' => Order::METHOD_COD,
            'label' => 'Cash on delivery',
            'description' => 'Pay in cash when your parts arrive.',
        ]];

        if ($this->fib->isConfigured()) {
            array_unshift($methods, [
                'id' => Order::METHOD_FIB_CARD,
                'label' => 'Pay by bank card',
                'description' => 'All Iraqi bank cards accepted. You will be taken to a secure page to pay.',
                'cards' => ['visa', 'mastercard', 'qicard'],
            ]);
        }

        if ($this->fibQr->isConfigured()) {
            $methods[] = [
                'id' => Order::METHOD_FIB_QR,
                'label' => 'Pay with FIB QR',
                'description' => 'Scan the code or open the FIB app to pay.',
            ];
        }

        return response()->json(['data' => $methods]);
    }

    /**
     * Place an order.
     *
     * The cart sends product IDs and quantities only. Prices are re-derived
     * from the ERP and stock re-checked inside the checkout service, so
     * nothing the browser claims about price is trusted.
     */
    public function store(Request $request): JsonResponse
    {
        $allowedMethods = [Order::METHOD_COD];

        if ($this->fib->isConfigured()) {
            $allowedMethods[] = Order::METHOD_FIB_CARD;
        }

        if ($this->fibQr->isConfigured()) {
            $allowedMethods[] = Order::METHOD_FIB_QR;
        }

        $input = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', $allowedMethods)],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:32'],
            'customer.city' => ['required', 'string', 'max:96'],
            'customer.address' => ['required', 'string', 'max:255'],
            'customer.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $account = AccountController::user($request);
            $order = $this->checkout->placeOrder(
                $input['items'],
                $input['customer'],
                $input['payment_method'],
                $account,
            );
            if ($account) {
                $order->forceFill(['user_id' => $account->id])->save();
                $account->forceFill([
                    'city' => $input['customer']['city'],
                    'address' => $input['customer']['address'],
                ])->save();
            }
        } catch (CheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $payload = [
            'data' => new OrderResource($order),
            // The key stands in for a customer login: without it the order
            // cannot be read back. The browser stores it.
            'access_key' => $order->access_key,
        ];

        if ($order->payment_method === Order::METHOD_FIB_CARD) {
            try {
                $payload['redirect_url'] = $this->startCardPayment($order);
            } catch (RuntimeException $exception) {
                Log::warning(sprintf('[fib-cards] order %s could not start: %s', $order->reference, $exception->getMessage()));

                return response()->json([
                    'message' => 'The card payment service is not responding. Please try again, or choose cash on delivery.',
                    'reference' => $order->reference,
                    'access_key' => $order->access_key,
                ], 502);
            }
        }

        if ($order->payment_method === Order::METHOD_FIB_QR) {
            try {
                $this->startQrPayment($order);
                $payload['data'] = new OrderResource($order->fresh()->load('items'));
            } catch (RuntimeException $exception) {
                Log::warning(sprintf('[fib-qr] order %s could not start: %s', $order->reference, $exception->getMessage()));
                $payload['payment_error'] = 'The QR payment could not be started. You can retry from your order page.';
            }
        }

        return response()->json($payload, 201);
    }

    /**
     * Read one order back. The access key is required.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOrder($request, $reference);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => new OrderResource($order)]);
    }

    /**
     * The invoice as a downloadable PDF, in whichever storefront language the
     * customer was browsing in. mPDF is used rather than dompdf because it
     * reshapes and reorders Arabic and Kurdish (Sorani) glyphs correctly;
     * dompdf renders both as disconnected, visually-reversed letters.
     */
    public function invoice(Request $request, string $reference): Response|JsonResponse
    {
        $order = $this->findOrder($request, $reference);

        if ($order === null || ! $order->hasInvoice()) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $lang = in_array($request->query('lang'), ['ar', 'ku'], true) ? $request->query('lang') : 'en';

        return response($this->invoices->render($order->load('items'), $lang), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$order->reference.'-INV.pdf"',
        ]);
    }

    /**
     * Ask FIB where the payment stands, then report the order.
     *
     * The waiting page calls this while the customer is coming back from the
     * card page; it is also what confirms a payment when FIB's callback cannot
     * reach this server.
     */
    public function status(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOrder($request, $reference);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->payment_method === Order::METHOD_FIB_CARD && ! $order->isSettled()) {
            $order = $this->reconciler->reconcile($order, 'status check');
            $this->pushIfPaid($order);
        }

        if ($order->payment_method === Order::METHOD_FIB_QR && ! $order->isPaid() && $order->status !== Order::STATUS_ON_HOLD) {
            $order = $this->qrReconciler->reconcile($order, 'status check');
            $this->pushIfPaid($order);
        }

        return response()->json([
            'data' => new OrderResource($order->load('items')),
            'settled' => $order->isSettled(),
        ]);
    }

    /**
     * Start a fresh payment attempt for an order that is still unpaid — either
     * retrying the method already on the order, or switching to a different
     * one (e.g. a declined card falling back to cash on delivery, or a COD
     * order deciding to pay by card instead).
     *
     * Nothing has been captured for any order that reaches here — a card or
     * QR payment is only ever confirmed by the gateway's own status check, and
     * COD collects nothing until delivery — so switching method carries no
     * risk of double-charging or losing a payment. A customer whose card was
     * declined also gets a new FIB order ID rather than a reused one, which
     * would collide with the failed attempt already at FIB.
     */
    public function retryPayment(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOrder($request, $reference);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Paid is final, and on_hold means a human is already reconciling a
        // contradictory gateway result — an order in either state should not
        // have its payment method changed out from under that.
        if ($order->isPaid() || $order->status === Order::STATUS_ON_HOLD) {
            return response()->json(['message' => 'This order cannot be paid again.'], 422);
        }

        $allowedMethods = [Order::METHOD_COD];
        if ($this->fib->isConfigured()) {
            $allowedMethods[] = Order::METHOD_FIB_CARD;
        }
        if ($this->fibQr->isConfigured()) {
            $allowedMethods[] = Order::METHOD_FIB_QR;
        }

        $input = $request->validate([
            'payment_method' => ['sometimes', 'string', 'in:'.implode(',', $allowedMethods)],
        ]);
        $method = $input['payment_method'] ?? $order->payment_method;

        // Leaving QR for another method: the open code must not stay payable,
        // and a code paid in the meantime settles the order instead.
        if ($method !== Order::METHOD_FIB_QR && $order->fibQrAttempts()->exists()) {
            $order = $this->qrReconciler->reconcile($order, 'before method change');
            if ($order->isPaid() || $order->status === Order::STATUS_ON_HOLD) {
                return response()->json(['message' => 'This order cannot be paid again.'], 422);
            }
            $this->qrReconciler->cancelOpenAttempts($order);
        }

        if ($method === Order::METHOD_COD) {
            $order->update(['payment_method' => Order::METHOD_COD, 'status' => Order::STATUS_AWAITING_DELIVERY]);

            return response()->json(['data' => new OrderResource($order->fresh()->load('items'))]);
        }

        if ($method === Order::METHOD_FIB_QR) {
            if ($order->fibQrAttempts()->exists()) {
                $order = $this->qrReconciler->reconcile($order, 'before retry');
            }
            if ($order->isPaid() || $order->status === Order::STATUS_ON_HOLD) {
                return response()->json(['message' => 'This order cannot be paid again.'], 422);
            }

            $latest = $order->fibQrAttempts()->latest('id')->first();
            if ($latest !== null && $order->status === Order::STATUS_PENDING && $order->payment_method === Order::METHOD_FIB_QR
                && ($latest->valid_until === null || $latest->valid_until->isFuture())) {
                return response()->json(['message' => 'This QR payment is still active.'], 422);
            }

            $order->update(['payment_method' => Order::METHOD_FIB_QR]);

            try {
                $this->startQrPayment($order);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => 'The QR payment service is not responding.'], 502);
            }

            $order->update(['status' => Order::STATUS_PENDING]);

            return response()->json(['data' => new OrderResource($order->fresh()->load('items'))]);
        }

        $order->update(['payment_method' => Order::METHOD_FIB_CARD]);

        try {
            $redirect = $this->startCardPayment($order);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'The card payment service is not responding.'], 502);
        }

        $order->update(['status' => Order::STATUS_PENDING]);

        return response()->json(['redirect_url' => $redirect]);
    }

    /**
     * @throws RuntimeException
     */
    private function startCardPayment(Order $order): string
    {
        $fibOrderId = FibPaymentAttempt::newFibOrderId();

        $result = $this->fib->initiatePayment(
            $fibOrderId,
            $order->total,
            $this->clientInfo($order),
            route('payments.fib.return', ['reference' => $order->reference]),
            (string) config('fib.callback_url'),
        );

        $order->fibAttempts()->create([
            'fib_order_id' => $fibOrderId,
            'payment_id' => $result['payment_id'],
            'expected_amount' => $order->total,
        ]);

        return $result['redirect_url'];
    }

    private function startQrPayment(Order $order): void
    {
        $result = $this->fibQr->createPayment($order->total, $order->reference);
        $order->fibQrAttempts()->create([
            'payment_id' => $result['paymentId'],
            'expected_amount' => $order->total,
            'qr_code' => $result['qrCode'],
            'readable_code' => $result['readableCode'] ?? null,
            'personal_app_link' => $this->safeAppLink($result['personalAppLink'] ?? null),
            'business_app_link' => $this->safeAppLink($result['businessAppLink'] ?? null),
            'corporate_app_link' => $this->safeAppLink($result['corporateAppLink'] ?? null),
            'valid_until' => isset($result['validUntil']) ? Carbon::parse($result['validUntil']) : null,
        ]);
    }

    private function safeAppLink(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }

    /**
     * FIB validates clientInfo, so every field is sent with a usable value.
     * Text is folded to ASCII because FIB rejects a non-ASCII body as a bad
     * signature; the customer's real details stay on our order.
     *
     * @return array<string, string>
     */
    private function clientInfo(Order $order): array
    {
        return [
            'clientName' => FibCardsClient::clamp(FibCardsClient::ascii($order->customer_name, 'Customer'), 64),
            'mobilePhone' => FibCardsClient::clamp(FibCardsClient::ascii($order->customer_phone), 20),
            'addressLine1' => FibCardsClient::clamp(FibCardsClient::ascii($order->address_line, 'Erbil'), 64),
            'addressLine2' => FibCardsClient::clamp(FibCardsClient::ascii($order->city, 'Iraq'), 64),
            'email' => '',
        ];
    }

    private function pushIfPaid(Order $order): void
    {
        if ($order->isPaid()) {
            $this->pusher->push($order);
        }
    }

    /**
     * The customer gave up on an unpaid QR order — typically to pay another
     * way, or with a changed cart. The QR is checked one last time (a code
     * paid at the last second settles the order instead), then cancelled at
     * FIB and the order closed, so the old code cannot be paid on top of the
     * new order.
     */
    public function abandon(Request $request, string $reference): JsonResponse
    {
        $order = $this->findOrder($request, $reference);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status === Order::STATUS_PENDING && $order->fibQrAttempts()->exists()) {
            $order = $this->qrReconciler->reconcile($order, 'before abandon');
        }

        if ($order->status === Order::STATUS_PENDING) {
            $this->qrReconciler->cancelOpenAttempts($order);
            $order->update(['status' => Order::STATUS_CANCELLED]);
        }

        return response()->json(['data' => new OrderResource($order->fresh()->load('items'))]);
    }

    private function findOrder(Request $request, string $reference): ?Order
    {
        $key = (string) $request->query('key', '');
        $order = Order::with('items')->where('reference', $reference)->first();

        // A wrong key is reported the same way as a missing order, so the
        // endpoint cannot be used to discover which references exist.
        if ($order === null || $key === '' || ! $order->accessKeyMatches($key)) {
            return null;
        }

        return $order;
    }
}

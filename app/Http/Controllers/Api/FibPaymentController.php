<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FibPaymentAttempt;
use App\Models\Order;
use App\Services\DolibarrOrderPusher;
use App\Services\FibCardsClient;
use App\Services\FibPaymentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where FIB talks back to us.
 *
 * Neither entry point is trusted as evidence. The callback body and the
 * browser's return query string are both treated only as a signal to go and
 * ask FIB what actually happened; the payment is decided by Check Payment
 * Status inside the reconciler.
 */
class FibPaymentController extends Controller
{
    public function __construct(
        private readonly FibPaymentReconciler $reconciler,
        private readonly DolibarrOrderPusher $pusher,
    ) {}

    /**
     * FIB's server-to-server callback.
     *
     * The body is used only to work out which order to look at. Whatever it
     * claims about the outcome is ignored; FIB status is still authoritative.
     */
    public function callback(Request $request): JsonResponse
    {
        if (! app(FibCardsClient::class)->verifyCallback($request->getContent(), $request->header('X-HMAC'))) {
            return response()->json(['message' => 'Invalid callback signature.'], 401);
        }

        $fibOrderId = (string) $request->input('orderId', '');
        Log::info('[fib-cards] callback received for FIB order '.($fibOrderId !== '' ? $fibOrderId : 'unknown'));

        $attempt = $fibOrderId !== ''
            ? FibPaymentAttempt::with('order')->where('fib_order_id', $fibOrderId)->first()
            : null;

        // Acknowledge regardless; asking FIB to retry an order we do not have
        // helps nobody, and a 200 here is not a claim that anything was paid.
        if ($attempt?->order === null) {
            return response()->json(['received' => true]);
        }

        $order = $this->reconciler->reconcile($attempt->order, 'callback');
        $this->pushIfPaid($order);

        return response()->json(['received' => true]);
    }

    /**
     * Where FIB sends the customer's browser back.
     *
     * The query string is not evidence, so the result is fetched from FIB
     * before the customer is sent anywhere. They always land on the order page,
     * which shows the real state — including "still settling" if that is the
     * truth.
     */
    public function return(Request $request, string $reference): RedirectResponse
    {
        $order = Order::with('items')->where('reference', $reference)->first();

        if ($order === null) {
            return redirect()->away($this->storefront('/shop'));
        }

        if (! $order->isSettled()) {
            $order = $this->reconciler->reconcile($order, 'return from FIB');
            $this->pushIfPaid($order);
        }

        // The access key travels back to the browser in the URL because the
        // customer is returning from a third-party site with no session. It is
        // the same key the browser already stored when the order was placed.
        return redirect()->away($this->storefront(sprintf(
            '/order/%s?key=%s',
            rawurlencode($order->reference),
            rawurlencode($order->access_key),
        )));
    }

    private function pushIfPaid(Order $order): void
    {
        if ($order->isPaid()) {
            $this->pusher->push($order);
        }
    }

    private function storefront(string $path): string
    {
        return rtrim((string) config('fib.storefront_url'), '/').$path;
    }
}

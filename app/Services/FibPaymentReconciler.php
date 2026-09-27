<?php

namespace App\Services;

use App\Models\FibPaymentAttempt;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Decides whether a FIB card order has actually been paid.
 *
 * The trust model, carried over from the WooCommerce gateway: the customer's
 * browser coming back proves nothing, and FIB's callback is treated only as a
 * "come and look" trigger. An order is marked paid solely from Check Payment
 * Status, which FIB calls the source of truth.
 */
class FibPaymentReconciler
{
    public function __construct(private readonly FibCardsClient $fib) {}

    /**
     * Bring an order's status into line with what FIB reports.
     *
     * @param  string  $source  Where the check was triggered from, for the audit trail.
     */
    public function reconcile(Order $order, string $source): Order
    {
        if ($order->isSettled()) {
            return $order;
        }

        // Newest first: the current attempt is the one whose outcome decides
        // this order, but an older attempt that actually took money still counts.
        $attempts = $order->fibAttempts()->orderByDesc('id')->get();
        $current = true;

        foreach ($attempts as $attempt) {
            try {
                $result = $this->fib->paymentStatus($attempt->fib_order_id, $attempt->payment_id);
            } catch (RuntimeException $exception) {
                // Only the current attempt is worth reporting; an older one
                // failing to answer says nothing about this order.
                if ($current) {
                    $this->log($order, 'Could not read the FIB payment status: '.$exception->getMessage());

                    return $order;
                }

                $current = false;

                continue;
            }

            // FIB answers 404 "No transaction found" until the customer has
            // actually completed the card page. That is a normal waiting
            // state, not a failure.
            if ($this->isNoTransaction($result)) {
                $current = false;

                continue;
            }

            $state = self::readState($result['data']);

            // An abandoned earlier attempt must not fail an order whose current
            // attempt is still open. Money actually taken always counts.
            if (! $current && ! in_array($state, FibCardsClient::PAID_STATES, true)) {
                continue;
            }

            $this->applyResult($order, $result['data'], $state, $source, $attempt);

            return $order->refresh();
        }

        return $order;
    }

    /**
     * @param  array{http: int, data: array<string, mixed>}  $result
     */
    private function isNoTransaction(array $result): bool
    {
        return $result['http'] === FibCardsClient::NO_TRANSACTION
            || (string) ($result['data']['errorCode'] ?? '') === '404';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyResult(Order $order, array $data, string $state, string $source, FibPaymentAttempt $attempt): void
    {
        $payment = self::paymentData($data);
        $returnCode = (string) ($payment['returnCode'] ?? FibCardsClient::APPROVED_RETURN_CODE);

        $attempt->update(['last_status' => $state, 'last_return_code' => $returnCode]);

        if (in_array($state, FibCardsClient::PAID_STATES, true)) {
            // A success must also carry the approval code. If FIB ever reports
            // a successful-looking status alongside a non-approval return code,
            // that disagreement is not resolved in the store's favour.
            if ($returnCode !== FibCardsClient::APPROVED_RETURN_CODE) {
                $order->update(['status' => Order::STATUS_ON_HOLD]);
                $this->log($order, sprintf(
                    'FIB reports status "%s" but return code %s (%s), which is not an approval. Held for manual review; not treated as paid.',
                    $state,
                    $returnCode,
                    (string) ($payment['codeDescription'] ?? 'no description'),
                ));

                return;
            }

            // The amount FIB settled must be the amount this order is worth.
            // A mismatch is a discrepancy for a human, never an auto-approval.
            if (! $this->amountMatches($attempt, $payment)) {
                $order->update(['status' => Order::STATUS_ON_HOLD]);
                $this->log($order, sprintf(
                    'FIB reports a successful payment of %s but the order expects %d IQD. Held for manual review.',
                    (string) ($payment['amount'] ?? 'an unknown amount'),
                    $attempt->expected_amount,
                ));

                return;
            }

            $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);
            $this->log($order, sprintf('Payment confirmed by FIB (%s), status "%s".', $source, $state));

            return;
        }

        if (in_array($state, FibCardsClient::CANCELLED_STATES, true)) {
            $order->update(['status' => Order::STATUS_CANCELLED]);
            $this->log($order, sprintf('FIB reports the payment was cancelled (%s), status "%s".', $source, $state));

            return;
        }

        if (in_array($state, FibCardsClient::FAILED_STATES, true)) {
            $order->update(['status' => Order::STATUS_FAILED]);
            $this->log($order, sprintf('FIB reports the payment failed (%s), status "%s".', $source, $state));

            return;
        }

        // FIB also uses ERROR while the card page is merely open. A 909
        // response after card entry is not documented as a final decline.
        if ($state === 'ERROR' && $returnCode === '909' && ! empty($payment['hiddenCardNumber'])) {
            $order->update(['status' => Order::STATUS_ON_HOLD]);
            $this->log($order, sprintf('FIB reports an unresolved card attempt (%s), return code %s. Held for manual review.', $source, $returnCode));

            return;
        }

        // Anything left — including "ERROR", which UAT produces merely from
        // opening the card page — means the payment is still in progress.
        $this->log($order, sprintf('FIB reports status "%s" (%s); still waiting.', $state, $source));
    }

    /**
     * Whether the settled amount equals what this attempt expected.
     *
     * An amount FIB does not report cannot be contradicted, so it is accepted;
     * the approval code has already been checked by this point.
     *
     * @param  array<string, mixed>  $payment
     */
    private function amountMatches(FibPaymentAttempt $attempt, array $payment): bool
    {
        if (! isset($payment['amount'])) {
            return true;
        }

        return (int) round((float) $payment['amount']) === $attempt->expected_amount;
    }

    /**
     * The result lives under paymentData when present, otherwise at the top level.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function paymentData(array $data): array
    {
        $payment = $data['paymentData'] ?? null;

        return is_array($payment) ? $payment : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function readState(array $data): string
    {
        $payment = self::paymentData($data);

        foreach (['status', 'paymentStatus', 'state'] as $key) {
            if (isset($payment[$key]) && is_scalar($payment[$key]) && (string) $payment[$key] !== '') {
                return strtoupper(trim((string) $payment[$key]));
            }
        }

        return '';
    }

    private function log(Order $order, string $message): void
    {
        Log::info(sprintf('[fib-cards] order %s: %s', $order->reference, $message));
    }
}

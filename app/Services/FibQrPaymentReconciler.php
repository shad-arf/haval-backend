<?php

namespace App\Services;

use App\Models\FibQrPaymentAttempt;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FibQrPaymentReconciler
{
    public function __construct(private readonly FibQrClient $fib) {}

    public function reconcile(Order $order, string $source): Order
    {
        if ($order->isPaid() || $order->status === Order::STATUS_ON_HOLD) {
            return $order;
        }

        $attempts = $order->fibQrAttempts()->orderByDesc('id')->get();
        $currentAttemptId = $attempts->first()?->id;
        $currentState = null;
        $currentDeclineReason = null;

        foreach ($attempts as $attempt) {
            try {
                $data = $this->fib->paymentStatus($attempt->payment_id);
            } catch (RuntimeException $exception) {
                Log::warning('[fib-qr] status check failed for order '.$order->reference.': '.$exception->getMessage());

                continue;
            }

            if ((string) ($data['paymentId'] ?? '') !== $attempt->payment_id) {
                Log::warning('[fib-qr] mismatched payment ID for order '.$order->reference);

                continue;
            }

            $state = strtoupper((string) ($data['status'] ?? ''));
            $attempt->update(['last_status' => $state]);

            if ($state === 'PAID') {
                if (! $this->amountMatches($attempt, $data)) {
                    $order->update(['status' => Order::STATUS_ON_HOLD]);
                    Log::warning('[fib-qr] paid amount or currency mismatch for order '.$order->reference);

                    return $order->refresh();
                }

                $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);
                Log::info('[fib-qr] payment confirmed for order '.$order->reference.' via '.$source);

                return $order->refresh();
            }

            if ($attempt->id === $currentAttemptId) {
                $currentState = $state;
                $currentDeclineReason = strtoupper((string) ($data['decliningReason'] ?? ''));
            }
        }

        if ($currentState === 'DECLINED') {
            $order->update(['status' => $currentDeclineReason === 'PAYMENT_CANCELLATION'
                ? Order::STATUS_CANCELLED : Order::STATUS_FAILED]);
        }

        return $order->refresh();
    }

    /**
     * Close every QR code still open on an unpaid order, so a customer who
     * switched to another way of paying cannot also pay the old code. A
     * failed cancel is logged and left to expire on FIB's side.
     */
    public function cancelOpenAttempts(Order $order): void
    {
        foreach ($order->fibQrAttempts()->get() as $attempt) {
            if (in_array($attempt->last_status, ['PAID', 'DECLINED'], true)) {
                continue;
            }

            try {
                $this->fib->cancelPayment($attempt->payment_id);
                $attempt->update(['last_status' => 'DECLINED']);
            } catch (RuntimeException $exception) {
                Log::warning('[fib-qr] cancel failed for order '.$order->reference.': '.$exception->getMessage());
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function amountMatches(FibQrPaymentAttempt $attempt, array $data): bool
    {
        $amount = $data['amount'] ?? null;

        return is_array($amount)
            && ($amount['currency'] ?? null) === 'IQD'
            && isset($amount['amount'])
            && is_numeric($amount['amount'])
            && (float) $amount['amount'] === (float) $attempt->expected_amount;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\DolibarrOrderPusher;
use App\Services\FibCardsClient;
use App\Services\FibPaymentReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fib:reconcile-payments')]
#[Description('Check pending FIB card payments even when the customer has closed the website')]
class ReconcileFibPayments extends Command
{
    public function handle(FibCardsClient $fib, FibPaymentReconciler $reconciler, DolibarrOrderPusher $pusher): int
    {
        if (! $fib->isConfigured()) {
            $this->info('FIB card gateway is not configured; no payments checked.');

            return self::SUCCESS;
        }

        $checked = 0;
        Order::query()
            ->where('payment_method', Order::METHOD_FIB_CARD)
            ->where('status', Order::STATUS_PENDING)
            ->whereHas('fibAttempts')
            ->lazyById(100)
            ->each(function (Order $order) use ($reconciler, $pusher, &$checked): void {
                $order = $reconciler->reconcile($order, 'scheduled status check');
                if ($order->isPaid()) {
                    $pusher->push($order);
                }
                $checked++;
            });

        $this->info(sprintf('Checked %d pending FIB card payments.', $checked));

        return self::SUCCESS;
    }
}

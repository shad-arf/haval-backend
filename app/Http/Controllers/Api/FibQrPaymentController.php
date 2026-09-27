<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FibQrPaymentAttempt;
use App\Services\DolibarrOrderPusher;
use App\Services\FibQrPaymentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FibQrPaymentController extends Controller
{
    public function __construct(
        private readonly FibQrPaymentReconciler $reconciler,
        private readonly DolibarrOrderPusher $pusher,
    ) {}

    public function callback(Request $request): JsonResponse
    {
        $paymentId = (string) $request->input('id', '');
        $attempt = $paymentId !== ''
            ? FibQrPaymentAttempt::with('order')->where('payment_id', $paymentId)->first()
            : null;

        if ($attempt?->order !== null) {
            $order = $this->reconciler->reconcile($attempt->order, 'callback');
            if ($order->isPaid()) {
                $this->pusher->push($order);
            }
        }

        return response()->json(['received' => true]);
    }
}

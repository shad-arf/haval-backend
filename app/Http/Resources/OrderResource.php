<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'placed_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),

            /**
             * Where the parts are, as opposed to whether they are paid for.
             * `available` is false while the order is unpaid or cancelled —
             * the stage is then meaningless and the UI hides its controls.
             */
            'fulfilment' => [
                'status' => $this->fulfilment_status,
                'updated_at' => $this->fulfilment_updated_at?->toIso8601String(),
                'available' => $this->isFulfillable(),
            ],

            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
                'city' => $this->city,
                'address' => $this->address_line,
                'notes' => $this->notes,
            ],

            'items' => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->erp_product_id,
                'ref' => $item->ref,
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                // Admin only: the returns relation is loaded on admin responses.
                'returned_quantity' => $this->whenLoaded('returns', fn () => (int) $this->returns
                    ->flatMap->items->where('order_item_id', $item->id)->sum('quantity')),
            ])->all(),

            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(static fn ($return): array => [
                'reference' => $return->reference,
                'total' => $return->total,
                'reason' => $return->reason,
                'created_at' => $return->created_at?->toIso8601String(),
                'items' => $return->items->map(static fn ($line): array => [
                    'ref' => $line->orderItem?->ref,
                    'name' => $line->orderItem?->name,
                    'quantity' => $line->quantity,
                    'line_total' => $line->line_total,
                ])->all(),
                'erp' => [
                    'credit_note_id' => $return->dolibarr_credit_note_id,
                    'refunded_at' => $return->dolibarr_refunded_at?->toIso8601String(),
                    'synced_at' => $return->isErpSynced() ? $return->dolibarr_synced_at?->toIso8601String() : null,
                    'error' => $return->dolibarr_error,
                ],
            ])->all()),

            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'currency' => $this->currency,

            'qr_payment' => $this->payment_method === Order::METHOD_FIB_QR && $this->status === Order::STATUS_PENDING
                ? $this->qrPaymentDetails()
                : null,

            /**
             * A paid order has an invoice the customer can view and print.
             * The ERP invoice number is included once the push has run.
             */
            'invoice' => $this->hasInvoice() ? [
                'number' => $this->reference.'-INV',
                'issued_at' => $this->paid_at?->toIso8601String(),
                'erp_invoice_id' => $this->dolibarr_invoice_id,
            ] : null,

            /**
             * Whether this order made it into Dolibarr as a sales order and
             * invoice. Only meaningful once paid — see DolibarrOrderPusher.
             */
            'erp' => [
                // False while DOLIBARR_PUSH_ORDERS is off, so the admin page
                // can say why a paid order never reached the ERP.
                'enabled' => (bool) config('dolibarr.push_orders'),
                'order_id' => $this->dolibarr_order_id,
                'invoice_id' => $this->dolibarr_invoice_id,
                'payment_recorded_at' => $this->dolibarr_paid_at?->toIso8601String(),
                // Null until every configured step — including the payment — is done.
                'synced_at' => $this->isErpSynced() ? $this->dolibarr_synced_at?->toIso8601String() : null,
                'error' => $this->dolibarr_error,
            ],
        ];
    }

    /** @return array<string, string|null>|null */
    private function qrPaymentDetails(): ?array
    {
        $attempt = $this->fibQrAttempts()->latest('id')->first();
        if ($attempt === null) {
            return null;
        }

        return [
            'qr_code' => $attempt->qr_code,
            'readable_code' => $attempt->readable_code,
            'personal_app_link' => $attempt->personal_app_link,
            'business_app_link' => $attempt->business_app_link,
            'corporate_app_link' => $attempt->corporate_app_link,
            'valid_until' => $attempt->valid_until?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes a confirmed order into Dolibarr as a sales order and a validated invoice.
 *
 * Every step is idempotent. The order reference is sent as `ref_client`, and
 * before creating anything the ERP is asked whether a document with that
 * reference already exists — so a retried push, a duplicated callback or a
 * second status check can never produce a second order or a second invoice.
 *
 * A failure here must never undo a payment that actually succeeded. The order
 * stays paid and the error is recorded for a retry; it is not surfaced to the
 * customer, who has already paid.
 */
class DolibarrOrderPusher
{
    public function __construct(private readonly DolibarrClient $dolibarr) {}

    public function enabled(): bool
    {
        return (bool) config('dolibarr.push_orders');
    }

    /**
     * Push an order, swallowing any failure into the order's error column.
     *
     * Returns true when the ERP holds both documents at the end of the call.
     */
    public function push(Order $order): bool
    {
        if (! $this->enabled() || ! $order->isPaid()) {
            return false;
        }

        if ($order->dolibarr_order_id !== null && $order->dolibarr_invoice_id !== null) {
            return true;
        }

        $customerId = (int) config('dolibarr.customer_id');

        if ($customerId <= 0) {
            $this->fail($order, 'No Dolibarr customer is configured (DOLIBARR_CUSTOMER_ID), so the order was not pushed.');

            return false;
        }

        try {
            $order->loadMissing('items');

            $orderId = $order->dolibarr_order_id ?? $this->createSalesOrder($order, $customerId);
            $order->update(['dolibarr_order_id' => $orderId]);

            $invoiceId = $order->dolibarr_invoice_id ?? $this->createInvoice($order, $customerId);
            $order->update([
                'dolibarr_invoice_id' => $invoiceId,
                'dolibarr_synced_at' => now(),
                'dolibarr_error' => null,
            ]);

            Log::info(sprintf('[erp-push] order %s -> Dolibarr order %d, invoice %d', $order->reference, $orderId, $invoiceId));

            return true;
        } catch (Throwable $exception) {
            $this->fail($order, $exception->getMessage());

            return false;
        }
    }

    /**
     * @throws RuntimeException
     */
    private function createSalesOrder(Order $order, int $customerId): int
    {
        $existing = $this->dolibarr->findByClientRef('orders', $order->reference);

        if ($existing > 0) {
            return $existing;
        }

        $id = $this->dolibarr->createOrder([
            'socid' => $customerId,
            'date' => $order->created_at?->getTimestamp() ?? time(),
            'type' => 0,
            'ref_client' => $order->reference,
            'note_private' => $this->note($order),
        ]);

        $this->addLines('orders', $id, $order);

        return $id;
    }

    /**
     * @throws RuntimeException
     */
    private function createInvoice(Order $order, int $customerId): int
    {
        $invoiceRef = $order->reference.'-INV';
        $existing = $this->dolibarr->findByClientRef('invoices', $invoiceRef);

        if ($existing > 0) {
            return $existing;
        }

        $id = $this->dolibarr->createInvoice([
            'socid' => $customerId,
            'type' => 0,
            'date' => $order->paid_at?->getTimestamp() ?? time(),
            'ref_client' => $invoiceRef,
            'note_private' => $this->note($order),
        ]);

        $this->addLines('invoices', $id, $order);

        // Validating is what turns a draft into a real accounting document.
        $this->dolibarr->validateInvoice($id);

        return $id;
    }

    /**
     * Lines carry a marker so a partially completed push can tell which lines
     * it already wrote and add only the missing ones.
     *
     * @throws RuntimeException
     */
    private function addLines(string $endpoint, int $documentId, Order $order): void
    {
        $existing = $this->dolibarr->documentLineMarkers($endpoint, $documentId);

        foreach ($order->items as $item) {
            $marker = sprintf('[%s-ITEM-%d]', $order->reference, $item->id);

            if (in_array($marker, $existing, true)) {
                continue;
            }

            $this->dolibarr->addDocumentLine($endpoint, $documentId, [
                'desc' => sprintf('%s %s | %s | %d IQD', $marker, $item->ref, $item->name, $item->unit_price),
                'qty' => $item->quantity,
                'subprice' => $item->unit_price,
                'tva_tx' => (float) config('dolibarr.vat_rate'),
                'fk_product' => $item->erp_product_id,
            ]);
        }
    }

    private function note(Order $order): string
    {
        return sprintf(
            'Web order %s. Customer: %s. Phone: %s. City: %s. Payment: %s.',
            $order->reference,
            $order->customer_name,
            $order->customer_phone,
            $order->city,
            match ($order->payment_method) {
                Order::METHOD_COD => 'Cash on delivery',
                Order::METHOD_FIB_QR => 'FIB QR',
                default => 'Bank card',
            },
        );
    }

    private function fail(Order $order, string $message): void
    {
        $order->update(['dolibarr_error' => $message]);
        Log::warning(sprintf('[erp-push] order %s failed: %s', $order->reference, $message));
    }
}

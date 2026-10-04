<?php

namespace App\Services;

use App\Exceptions\DolibarrRequestException;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes a confirmed order into Dolibarr as a validated invoice, preceded by a
 * sales order unless DOLIBARR_PUSH_SALES_ORDER is off.
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
    public function __construct(
        private readonly DolibarrClient $dolibarr,
        private readonly PricingEngine $pricing,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('dolibarr.push_orders');
    }

    /**
     * Push an order, swallowing any failure into the order's error column.
     *
     * Returns true when the ERP holds every document (and the payment) at the
     * end of the call.
     */
    public function push(Order $order): bool
    {
        if (! $this->enabled() || ! $order->isPaid()) {
            return false;
        }

        if ($order->isErpSynced()) {
            return true;
        }

        try {
            $order->loadMissing('items', 'user');

            $customerId = $this->customerFor($order);

            if ($customerId <= 0) {
                $this->fail($order, 'No Dolibarr customer is configured (DOLIBARR_CUSTOMER_ID), so the order was not pushed.');

                return false;
            }

            $orderId = $order->dolibarr_order_id;

            if ($orderId === null && $this->pushesSalesOrders()) {
                $orderId = $this->createSalesOrder($order, $customerId);
                $order->update(['dolibarr_order_id' => $orderId]);
            }

            $invoiceId = $order->dolibarr_invoice_id ?? $this->createInvoice($order, $customerId);
            $order->update(['dolibarr_invoice_id' => $invoiceId]);

            if ($order->dolibarr_paid_at === null && $this->recordsPayments()) {
                $this->recordPayment($order, $invoiceId);
                $order->update(['dolibarr_paid_at' => now()]);
            }

            $order->update(['dolibarr_synced_at' => now(), 'dolibarr_error' => null]);

            Log::info(sprintf('[erp-push] order %s -> Dolibarr order %s, invoice %d', $order->reference, $orderId ?? 'skipped', $invoiceId));

            return true;
        } catch (DolibarrRequestException $exception) {
            $this->fail($order, sprintf('%s (HTTP %d)', $exception->getMessage(), $exception->upstreamStatus));

            return false;
        } catch (Throwable $exception) {
            $this->fail($order, $exception->getMessage());

            return false;
        }
    }

    /**
     * Some Dolibarr installs bill straight to invoices with the Sales Orders
     * module switched off; the invoice alone carries the accounting and the
     * stock movement, so the sales order is optional.
     */
    private function pushesSalesOrders(): bool
    {
        return (bool) config('dolibarr.push_sales_orders');
    }

    private function recordsPayments(): bool
    {
        return (int) config('dolibarr.bank_account_id') > 0;
    }

    /**
     * Only paid orders are pushed, so the invoice is settled in full and
     * closed as paid. A retry first asks what is still owed, so a payment
     * that landed before a timeout is never recorded twice.
     *
     * @throws RuntimeException
     */
    private function recordPayment(Order $order, int $invoiceId): void
    {
        $invoice = $this->dolibarr->invoice($invoiceId);

        if ((int) ($invoice['paye'] ?? 0) === 1 || (float) ($invoice['remaintopay'] ?? 1) <= 0) {
            return;
        }

        $this->dolibarr->addInvoicePayment($invoiceId, [
            'datepaye' => $order->paid_at?->getTimestamp() ?? time(),
            'paymentid' => (int) config('dolibarr.payment_modes.'.$order->payment_method, config('dolibarr.payment_modes.fib_card')),
            'closepaidinvoices' => 'yes',
            'accountid' => $this->bankAccountFor($order),
            'num_payment' => $order->reference,
            'comment' => sprintf('Web order %s, paid %s IQD (%s).', $order->reference, number_format($order->total), $this->paymentLabel($order)),
        ]);
    }

    /**
     * A dealer whose account carries a Dolibarr customer code is booked
     * against that customer, so the sale shows on their ERP account. Everyone
     * else — and a dealer whose code matches nobody — goes to the generic
     * online customer.
     *
     * @throws RuntimeException
     */
    private function customerFor(Order $order): int
    {
        $code = trim((string) $order->user?->customer_code);

        if ($order->user?->role === 'dealer' && $code !== '') {
            $dealerId = $this->dolibarr->findCustomerByCode($code);

            if ($dealerId > 0) {
                return $dealerId;
            }

            Log::warning(sprintf('[erp-push] order %s: dealer customer code %s matches no Dolibarr customer, using the online customer', $order->reference, $code));
        }

        return (int) config('dolibarr.customer_id');
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
        $this->dolibarr->validateInvoice($id, (int) config('dolibarr.warehouse_id'));

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
        $rate = $this->pricing->erpCurrency() === 'IQD' ? '' : sprintf(' @ %s IQD/USD', $this->pricing->usdIqdRate());

        foreach ($order->items as $item) {
            $marker = sprintf('[%s-ITEM-%d]', $order->reference, $item->id);

            if (in_array($marker, $existing, true)) {
                continue;
            }

            $this->dolibarr->addDocumentLine($endpoint, $documentId, [
                'desc' => sprintf('%s %s | %s | %d IQD%s', $marker, $item->ref, $item->name, $item->unit_price, $rate),
                'qty' => $item->quantity,
                // Shop prices are IQD; the ERP keeps its books in USD.
                'subprice' => $this->pricing->toErpCurrency($item->unit_price),
                'tva_tx' => (float) config('dolibarr.vat_rate'),
                'fk_product' => $item->erp_product_id,
            ]);
        }
    }

    private function note(Order $order): string
    {
        return sprintf(
            'Web order %s. Paid %s IQD. Customer: %s. Phone: %s. City: %s. Payment: %s.',
            $order->reference,
            number_format($order->total),
            $order->customer_name,
            $order->customer_phone,
            $order->city,
            $this->paymentLabel($order),
        );
    }

    private function bankAccountFor(Order $order): int
    {
        $online = (int) config('dolibarr.online_bank_account_id');

        return $order->payment_method !== Order::METHOD_COD && $online > 0
            ? $online
            : (int) config('dolibarr.bank_account_id');
    }

    private function paymentLabel(Order $order): string
    {
        return match ($order->payment_method) {
            Order::METHOD_COD => 'Cash on delivery',
            Order::METHOD_FIB_QR => 'FIB QR',
            default => 'Bank card',
        };
    }

    private function fail(Order $order, string $message): void
    {
        $order->update(['dolibarr_error' => $message]);
        Log::warning(sprintf('[erp-push] order %s failed: %s', $order->reference, $message));
    }
}

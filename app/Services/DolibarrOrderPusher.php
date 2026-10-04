<?php

namespace App\Services;

use App\Exceptions\DolibarrRequestException;
use App\Models\Order;
use App\Models\OrderReturn;
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
     * Push a return as a Dolibarr credit note against the order's invoice:
     * validated under the return's own reference (WEB-000010-R1) into the
     * configured warehouse, which puts the stock back, then refunded from the
     * account the money went into. Idempotent like push(); failures are kept
     * in the return's error column for a retry.
     */
    public function pushReturn(OrderReturn $return): bool
    {
        if (! $this->enabled() || $return->isErpSynced()) {
            return $return->isErpSynced();
        }

        $order = $return->order;

        if ($order->dolibarr_invoice_id === null) {
            $return->update(['dolibarr_error' => 'The order has no ERP invoice yet; sync the order first.']);

            return false;
        }

        try {
            $return->loadMissing('items.orderItem');

            $creditNoteId = $return->dolibarr_credit_note_id ?? $this->createCreditNote($return, $order);
            $return->update(['dolibarr_credit_note_id' => $creditNoteId]);

            if ($return->dolibarr_refunded_at === null && $this->recordsPayments()) {
                $this->recordRefund($return, $order, $creditNoteId);
                $return->update(['dolibarr_refunded_at' => now()]);
            }

            $return->update(['dolibarr_synced_at' => now(), 'dolibarr_error' => null]);
            Log::info(sprintf('[erp-push] return %s -> Dolibarr credit note %d', $return->reference, $creditNoteId));

            return true;
        } catch (DolibarrRequestException $exception) {
            $message = sprintf('%s (HTTP %d)', $exception->getMessage(), $exception->upstreamStatus);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }

        $return->update(['dolibarr_error' => $message]);
        Log::warning(sprintf('[erp-push] return %s failed: %s', $return->reference, $message));

        return false;
    }

    /**
     * @throws RuntimeException
     */
    private function createCreditNote(OrderReturn $return, Order $order): int
    {
        // A retry may find a draft an interrupted attempt left behind; it is
        // completed and validated rather than duplicated.
        $id = $this->dolibarr->findByClientRef('invoices', $return->reference);

        if ($id > 0) {
            $draft = $this->dolibarr->invoice($id);

            if ((int) ($draft['status'] ?? $draft['statut'] ?? 1) !== 0) {
                return $id;
            }
        } else {
            // Booked to whoever the original invoice was booked to — a dealer's
            // return lands on the dealer's account even if their code changed since.
            $invoice = $this->dolibarr->invoice((int) $order->dolibarr_invoice_id);

            $id = $this->dolibarr->createInvoice([
                'socid' => (int) $invoice['socid'],
                'type' => 2,
                'fk_facture_source' => (int) $order->dolibarr_invoice_id,
                'date' => $return->created_at?->getTimestamp() ?? time(),
                'ref_client' => $return->reference,
                'note_private' => sprintf('Return %s of web order %s. Refund %s IQD.%s', $return->reference, $order->reference, number_format($return->total), $return->reason ? ' Reason: '.$return->reason : ''),
            ]);
        }

        $existingLines = $this->dolibarr->documentLineMarkers('invoices', $id);
        $rate = $this->pricing->erpCurrency() === 'IQD' ? '' : sprintf(' @ %s IQD/USD', $this->pricing->usdIqdRate());

        foreach ($return->items as $item) {
            $marker = sprintf('[%s-ITEM-%d]', $return->reference, $item->order_item_id);

            if (in_array($marker, $existingLines, true)) {
                continue;
            }

            // Positive amounts: Dolibarr flips the sign on credit note lines itself.
            $this->dolibarr->addDocumentLine('invoices', $id, [
                'desc' => sprintf('%s %s | %s | %d IQD%s', $marker, $item->orderItem->ref, $item->orderItem->name, $item->unit_price, $rate),
                'qty' => $item->quantity,
                'subprice' => $this->pricing->toErpCurrency($item->unit_price),
                'tva_tx' => (float) config('dolibarr.vat_rate'),
                'fk_product' => $item->orderItem->erp_product_id,
            ]);
        }

        $this->dolibarr->validateInvoice($id, (int) config('dolibarr.warehouse_id'), $return->reference);

        return $id;
    }

    /**
     * @throws RuntimeException
     */
    private function recordRefund(OrderReturn $return, Order $order, int $creditNoteId): void
    {
        $creditNote = $this->dolibarr->invoice($creditNoteId);

        // A credit note owes a negative amount; nothing left means already refunded.
        if ((int) ($creditNote['paye'] ?? 0) === 1 || abs((float) ($creditNote['remaintopay'] ?? 1)) < 0.005) {
            return;
        }

        $this->dolibarr->addCreditNoteRefund($creditNoteId, [
            'datepaye' => time(),
            'paymentid' => (int) config('dolibarr.payment_modes.'.$order->payment_method, config('dolibarr.payment_modes.fib_card')),
            'closepaidinvoices' => 'yes',
            'accountid' => $this->bankAccountFor($order),
            'num_payment' => $return->reference,
            'comment' => sprintf('Refund for return %s of web order %s, %s IQD (%s).', $return->reference, $order->reference, number_format($return->total), $this->paymentLabel($order)),
        ]);
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
        // The ERP invoice carries the web order number (WEB-000010) so the two
        // systems name the sale the same way; it stays outside the FA sequence.
        $this->dolibarr->validateInvoice($id, (int) config('dolibarr.warehouse_id'), $order->reference);

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

<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilment is tracked separately from payment.
 *
 * `status` answers "has this been paid for", and the FIB reconciler, the
 * Dolibarr push and `hasInvoice()` all read it. Where the goods physically
 * are is a second, independent question: a paid order can still be waiting
 * on a supplier, and a cash-on-delivery order is prepared and driven out
 * long before any money changes hands. Folding both into one column would
 * mean an order in `preparing` no longer counted as paid, and its invoice
 * would disappear from under the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('fulfilment_status', 24)
                ->default(Order::FULFILMENT_NOT_STARTED)
                ->index()
                ->after('payment_method');

            /** When the stage last moved, so the admin timeline can say how long it has sat. */
            $table->timestamp('fulfilment_updated_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['fulfilment_status']);
            $table->dropColumn(['fulfilment_status', 'fulfilment_updated_at']);
        });
    }
};

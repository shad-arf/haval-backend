<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            /** Customer-facing order number, also the idempotency key in Dolibarr. */
            $table->string('reference', 32)->unique();

            /**
             * Secret required to view an order. There are no customer accounts,
             * so an order is readable only by someone holding this key — the
             * reference alone is guessable and must never be enough.
             */
            $table->string('access_key', 64);

            $table->string('status', 24)->index();
            $table->string('payment_method', 24);

            $table->string('customer_name');
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();
            $table->string('city', 96);
            $table->string('address_line');
            $table->text('notes')->nullable();

            /** IQD has no minor unit, so money is stored as whole dinars. */
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('total');
            $table->char('currency', 3)->default('IQD');

            $table->timestamp('paid_at')->nullable();

            /** Set once the order reaches Dolibarr; also the guard against double-pushing. */
            $table->unsignedBigInteger('dolibarr_order_id')->nullable();
            $table->unsignedBigInteger('dolibarr_invoice_id')->nullable();
            $table->timestamp('dolibarr_synced_at')->nullable();
            $table->text('dolibarr_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

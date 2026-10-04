<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods a customer sent back after paying. Each return becomes its own
     * credit note in Dolibarr (WEB-000010-R1), so one order can have several
     * partial returns.
     */
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('total');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('dolibarr_credit_note_id')->nullable();
            $table->timestamp('dolibarr_refunded_at')->nullable();
            $table->timestamp('dolibarr_synced_at')->nullable();
            $table->text('dolibarr_error')->nullable();
            $table->timestamps();
        });

        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /**
             * A snapshot taken at checkout, not a live reference. The ERP can
             * rename or reprice a part afterwards; an order must still show
             * what the customer actually agreed to buy and pay.
             */
            $table->unsignedBigInteger('erp_product_id');
            $table->string('ref', 64);
            $table->string('name');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

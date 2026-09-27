<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fib_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /**
             * FIB keys the token, the payment and the status check on this
             * value, so every attempt gets a fresh one — reusing it after a
             * decline would collide with the failed attempt already at FIB.
             */
            $table->string('fib_order_id', 32)->unique();
            $table->string('payment_id', 64)->nullable();

            /**
             * Every attempt is kept, not just the latest. A customer who opens
             * the card page, goes back, starts a second attempt and then
             * completes the first would otherwise have paid against an ID the
             * store no longer checks, leaving a paid order sitting unpaid.
             */
            $table->string('last_status', 32)->nullable();
            $table->string('last_return_code', 16)->nullable();

            /** What the order was worth when this attempt started, in whole IQD. */
            $table->unsignedBigInteger('expected_amount');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fib_payment_attempts');
    }
};

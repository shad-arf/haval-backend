<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fib_qr_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('payment_id', 100)->unique();
            $table->unsignedBigInteger('expected_amount');
            $table->longText('qr_code');
            $table->string('readable_code', 64)->nullable();
            $table->text('personal_app_link')->nullable();
            $table->text('business_app_link')->nullable();
            $table->text('corporate_app_link')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fib_qr_payment_attempts');
    }
};

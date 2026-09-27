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
        Schema::create('product_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('erp_product_id')->unique();
            $table->string('ref')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->decimal('profit_percentage', 7, 2)->nullable();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_overrides');
    }
};

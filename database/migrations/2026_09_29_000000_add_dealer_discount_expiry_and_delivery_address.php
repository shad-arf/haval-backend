<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('discount_expires_at')->nullable();
            $table->boolean('show_discount_countdown')->default(false);
            $table->string('city', 96)->nullable();
            $table->string('address', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['discount_expires_at', 'show_discount_countdown', 'city', 'address']));
    }
};

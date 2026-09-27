<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('customer');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('api_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'discount_percent', 'is_active', 'api_token_hash', 'last_login_at']));
    }
};

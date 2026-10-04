<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dealers are now priced up from cost (cost + dealer profit) instead of
     * discounted down from the customer price. This optional per-dealer
     * profit overrides the shop-wide dealer_profit_percentage setting; the
     * old discount_percent column is no longer read.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('dealer_profit_percentage', 7, 2)->nullable()->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('dealer_profit_percentage'));
    }
};

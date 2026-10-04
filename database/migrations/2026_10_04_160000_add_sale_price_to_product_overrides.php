<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin-set IQD price below the normal one, for a sale or clearance.
     * It may be under cost — that is the admin's call, shown on the admin page.
     */
    public function up(): void
    {
        Schema::table('product_overrides', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_price')->nullable()->after('profit_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('product_overrides', fn (Blueprint $table) => $table->dropColumn('sale_price'));
    }
};

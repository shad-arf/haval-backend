<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Dolibarr customer code (code_client) a dealer's web orders are
     * booked against.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('customer_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('customer_code'));
    }
};

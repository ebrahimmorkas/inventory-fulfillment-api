<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The warehouse ledger endpoint lists movements newest first across all
     * products; the (warehouse_id, product_id, id) index cannot serve that order.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['warehouse_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'id']);
        });
    }
};

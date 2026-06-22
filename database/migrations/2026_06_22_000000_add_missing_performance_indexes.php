<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Issue 13: wallet_transactions(order_id, type, status)
        // CheckSupplierBalancesJob queries these three columns with lockForUpdate.
        // Without this index, lock-hold time grows linearly with table size.
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['order_id', 'type', 'status'], 'wt_order_type_status_idx');
        });

        // Issue 14: order_items.supplier_user_id
        // OrderBuilder::forSupplier() and CheckSupplierBalancesJob both filter on this column.
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('supplier_user_id', 'order_items_supplier_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wt_order_type_status_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_supplier_user_id_idx');
        });
    }
};

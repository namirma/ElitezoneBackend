<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CheckSupplierBalancesJob هر دقیقه orders را روی supplier_debt_pending فیلتر و
 * chunkById می‌کند؛ بدون ایندکس، در حالتِ رایجِ «هیچ بدهی معلقی نیست» کلِ PK جدول
 * پیمایش می‌شد. ایندکسِ دفاعی اضافه می‌کنیم (فقط اگر ستون باشد و ایندکس نباشد).
 */
return new class extends Migration
{
    private const INDEX = 'orders_supplier_debt_pending_idx';

    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'supplier_debt_pending') || $this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('orders', function ($table) {
            $table->index('supplier_debt_pending', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! $this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('orders', function ($table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'orders')
            ->where('index_name', $index)
            ->exists();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * stock_quantity به‌صورت integer بود، اما موجودی با وزن‌های کسری (کیلوگرم) در
 * InventoryService کم/زیاد می‌شود (باسکول، افت وزن، بازگردانی)؛ ذخیرهٔ integer باعث
 * گِردشدن و دریفتِ تجمعیِ موجودی می‌شد. ستون به decimal(12,3) تغییر می‌کند تا با
 * سمنتیکِ وزنیِ کد هم‌خوان شود (هم‌سو با کست‌های decimal سایر ستون‌های همین جدول).
 *
 * از ALTER خام استفاده می‌کنیم (به‌جای ->change()) تا روی لاراول ۱۰ به doctrine/dbal
 * نیاز نباشد. فقط MySQL؛ گِردکردنِ int→decimal بی‌خطر است (۱۰۰ → ۱۰۰.۰۰۰).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('product_variants', 'stock_quantity')) {
            return;
        }

        DB::statement('ALTER TABLE `product_variants` MODIFY `stock_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('product_variants', 'stock_quantity')) {
            return;
        }

        // بازگردانی به integer (مقادیر کسری در rollback گِرد می‌شوند).
        DB::statement('ALTER TABLE `product_variants` MODIFY `stock_quantity` INT NOT NULL DEFAULT 0');
    }
};

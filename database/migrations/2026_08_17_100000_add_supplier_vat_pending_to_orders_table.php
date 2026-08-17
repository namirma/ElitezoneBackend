<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders.supplier_vat_pending_amount` — مالیاتِ تأمین‌کننده که در لحظه‌ی
 * باسکول قابل هولد نبود (بند ۸.۴ نسخه‌ی ۲ سند).
 *
 * چرا ستونِ جدا و نه جمع‌شدن در `supplier_debt_amount`: آن ستون «طلبِ سازه از
 * تأمین‌کننده» است و کران آن را **به کیف پول سازه** وصول می‌کند. ولی مالیات
 * طلبِ سازه نیست — پولِ خودِ تأمین‌کننده است که فقط تا صدور فاکتور مودیان قفل
 * می‌ماند. ریختنِ آن در همان ستون یعنی مالیات برای همیشه نزد سازه بماند و
 * `RecordSupplierMoadianInvoiceAction` چیزی برای آزادکردن پیدا نکند.
 *
 * پس دو عدد جدا نگه داشته می‌شوند و کران هر کدام را به مقصد خودش می‌برد:
 * بدهی → سازه، مالیات → هولد در کیف پولِ خودِ تأمین‌کننده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'supplier_vat_pending_amount')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('supplier_vat_pending_amount', 20, 2)->nullable()
                ->after('supplier_debt_amount')
                ->comment('مالیات تأمین‌کننده که در باسکول قابل هولد نبود؛ کران پس از شارژ هولدش می‌کند');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'supplier_vat_pending_amount')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('supplier_vat_pending_amount');
        });
    }
};

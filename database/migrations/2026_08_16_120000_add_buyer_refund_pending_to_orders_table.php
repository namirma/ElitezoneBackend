<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عودتی که به خریدار بدهکاریم ولی هنوز پولش را نداریم — فاز ۸.
 *
 * تصمیم مدیریت (دور ۲ پرسش ۲، گزینه‌ی ب): وقتی تأمین‌کننده کیف پولش خالی است،
 * **شرکت پول را جلو نمی‌اندازد** و خریدار تا شارژِ تأمین‌کننده منتظر می‌ماند.
 *
 * پیش از این `SupplierDebtHandler` مبلغ را از کیف پول سازه برمی‌داشت و به
 * هولدِ خریدار می‌ریخت. کیف پول سازه بعد از پرداخت فقط کمیسیون را دارد، پس آن
 * برداشت می‌شکست و **کلِ باسکول rollback می‌شد** — یعنی با کیف پولِ خالی هیچ
 * کسری‌ای اصلاً قابل ثبت نبود.
 *
 * حالا در لحظه‌ی باسکول هیچ پولی جابه‌جا نمی‌شود؛ فقط دو عدد ثبت می‌گردد:
 * چقدر از تأمین‌کننده طلب داریم (`supplier_debt_amount`) و چقدر به خریدار
 * بدهکاریم (این ستون). `CheckSupplierBalancesJob` با شارژِ بعدی هر دو را در یک
 * تراکنش انجام می‌دهد. دفتر در هیچ لحظه‌ای نامتوازن نمی‌شود چون پولی که وجود
 * ندارد هم منتقل نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('buyer_refund_pending_amount', 15, 2)
                ->nullable()
                ->after('supplier_debt_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('buyer_refund_pending_amount');
        });
    }
};

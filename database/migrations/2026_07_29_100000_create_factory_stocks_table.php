<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * موجودی به‌ازای مبدأ — «کارخانه/انبار».
 *
 * تا امروز `product_variants.stock_quantity` یک ستونِ **سراسری برای هر واریانت**
 * بود، در حالی که قیمت به سه‌تاییِ (واریانت × کارخانه × انبار) گره خورده. یعنی دو
 * تأمین‌کننده‌ای که یک واریانت را از دو کارخانهٔ متفاوت می‌فروشند، **یک عددِ
 * موجودی مشترک** داشتند: فروشِ A از موجودیِ B کم می‌کرد و اگر به تأمین‌کننده
 * اجازهٔ نوشتن می‌دادیم، A می‌توانست موجودیِ B را صفر کند. به همین دلیل پنلِ
 * موجودیِ خودسرویس ساخته نشده بود — نقص در اسکیما بود نه در نبودِ کنترلر.
 *
 * کلیدِ این جدول عیناً همان کلیدِ `product_prices` است، چون «هر جا قیمت داری،
 * موجودی هم داری» دقیقاً همان واحدِ کسب‌وکاری است.
 *
 * ── دربارهٔ backfill ─────────────────────────────────────────────────────
 * عددِ سراسریِ امروز را **نمی‌شود** درست بین مبادی تقسیم کرد؛ آن اطلاعات در
 * دیتابیس وجود ندارد. دو انتخاب داشتیم:
 *
 *   الف) تقسیمِ مساوی — جمع درست می‌ماند ولی هر مبدأ ناگهان کسری از دیروز
 *        موجودی دارد و ممکن است سفارشِ واقعی روزِ استقرار رد شود.
 *   ب)  دادنِ کلِ عدد به هر مبدأ — دقیقاً همان رفتاری که **امروز** جاری است
 *        (هر مبدأ عملاً کلِ استخر را عرضه می‌کند)، پس هیچ سفارشی رد نمی‌شود.
 *
 * (ب) انتخاب شد: بلوکه شدنِ سفارشِ واقعی بدتر از یک عددِ نمایشیِ بزرگ‌نماست.
 * نتیجه‌اش این است که `stock_quantity` (که حالا فقط جمعِ نمایشی است) برای
 * واریانت‌های چندمبدأیی N برابر می‌شود. **تأمین‌کنندگان باید عددِ واقعیِ خودشان
 * را وارد کنند**؛ تا آن موقع سیستم دقیقاً مثل دیروز رفتار می‌کند، نه بدتر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_location_id')->constrained()->onDelete('cascade');

            // هم‌دقتِ product_variants.stock_quantity — موجودی وزنی/کسری است
            // (باسکول، افت وزن، بازگردانی)، پس integer دریفت می‌ساخت.
            $table->decimal('quantity', 12, 3)->default(0);

            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'factory_id', 'factory_location_id'],
                'unique_factory_stock'
            );

            // خواندنِ «موجودیِ این مبدأ» در چک/کسر سفارش داغ‌ترین کوئری است.
            $table->index(['factory_id', 'factory_location_id'], 'factory_stocks_origin_idx');
        });

        $this->backfillFromGlobalStock();
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_stocks');
    }

    /**
     * هر سه‌تاییِ دارای قیمت، موجودیِ سراسریِ همان واریانت را می‌گیرد — تکرارِ
     * دقیقِ رفتارِ امروز. یک INSERT…SELECT، پس روی کاتالوگ بزرگ هم ردیف‌به‌ردیف
     * نمی‌شود.
     */
    private function backfillFromGlobalStock(): void
    {
        if (! Schema::hasTable('product_prices') || ! Schema::hasTable('product_variants')) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO factory_stocks
                (product_variant_id, factory_id, factory_location_id, quantity, created_at, updated_at)
            SELECT
                pp.product_variant_id,
                pp.factory_id,
                pp.factory_location_id,
                COALESCE(pv.stock_quantity, 0),
                NOW(),
                NOW()
            FROM product_prices pp
            JOIN product_variants pv ON pv.id = pp.product_variant_id
        SQL);
    }
};

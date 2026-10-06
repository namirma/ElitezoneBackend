<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * پاسخ‌های مدیریت به «تصمیم‌های مدیریتیِ باقی‌مانده — دورِ پنجم» (۱۴۰۵/۰۷/۱۴) —
 * `docs/session/MANAGEMENT_ANSWERS_2026-10-06.md`.
 *
 * «به‌جز (۱) تخفیفِ خریدارِ دارای الیت‌یار طبقِ الگوریتم و (۲) تخفیفِ کوپن که از کیف پولِ
 * کوپن‌های تخفیف برداشته می‌شود، هیچ تخفیفِ دیگری در سیستم وجود ندارد.»
 *
 *  - `orders.elite_yar_discount_amount`: «تخفیف ویژه الیت یار» — سهمِ الیت‌یار وقتی برای خودش می‌خرد
 *    (پرسشِ ۱)؛ سطرِ جدا کنارِ `buyer_discount_amount`.
 *  - `coupon_funding_legs.wallet_code`: سهمِ شرکت از کدام کیف پول پرداخت شد (`WCPN` از این پس،
 *    NULL = لِگِ قدیمی از WSSG) تا استرداد و باسکول به همان کیف پول برگردند.
 *  - حسابِ سیستمیِ «کیف پول کوپن‌های تخفیف» (موبایلِ رزروشده‌ی 0990000003) + کیف پولش.
 *  - داده (قابلِ برگشت، هیچ حذفی — قانونِ یک): فیلترهای قیمتِ **منفی** (تخفیفِ نقش‌محور) و
 *    کوپن‌های مصرف‌نشده‌ی **بازگشتِ مشتری** غیرفعال می‌شوند. شناسه‌هایشان در جدولِ
 *    `round5_deactivations` می‌ماند تا `down()` دقیقاً همان‌ها را برگرداند.
 */
return new class extends Migration
{
    private const COUPON_WALLET_MOBILE = '0990000003';

    private const WIN_BACK_REASON = 'Win-Back Offer (System Auto)';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('elite_yar_discount_amount', 20, 0)->default(0)->after('buyer_discount_amount')
                ->comment('تخفیف ویژه الیت یار — سهم الیت‌یار وقتی برای خودش می‌خرد (مدیریت، دور پنجم، پرسش ۱)');
        });

        // پرسشِ ۷: اسنادِ تأمین‌کننده با قیمتِ خودِ او — قیمتِ هر ردیف پیش از فیلترهای نقش.
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('supplier_unit_price', 20, 4)->nullable()->after('unit_price');
        });

        Schema::table('coupon_funding_legs', function (Blueprint $table) {
            $table->string('wallet_code', 8)->nullable()->after('funder_user_id')
                ->comment('کیف پولی که سهم را داد: WCPN برای سهم شرکت از ۱۴۰۵/۰۷/۱۴؛ NULL = لِگ قدیمی (WSSG)');
        });

        Schema::create('round5_deactivations', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->timestamp('created_at')->useCurrent();
        });

        // ── حسابِ کیف پولِ کوپن ────────────────────────────────────────────────
        $userId = DB::table('users')->where('mobile', self::COUPON_WALLET_MOBILE)->value('id');
        if ($userId === null) {
            $userId = DB::table('users')->insertGetId([
                'mobile' => self::COUPON_WALLET_MOBILE,
                'password' => Hash::make(Str::random(48)),
                'is_active' => true,
                'type_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_profiles')->insert([
                'user_id' => $userId,
                'name' => 'کیف پول',
                'family' => 'کوپن‌های تخفیف',
                'national_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (! DB::table('wallets')->where('user_id', $userId)->exists()) {
            DB::table('wallets')->insert([
                'user_id' => $userId, 'balance' => 0, 'balance_hold' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── غیرفعال‌سازیِ تخفیف‌های «دیگر» ─────────────────────────────────────
        $negativeFilters = DB::table('price_filters')->where('is_active', true)->where('amount', '<', 0)->pluck('id');
        $winBack = DB::table('coupons')->where('is_active', true)->whereNull('used_at')
            ->where('reason', self::WIN_BACK_REASON)->pluck('id');

        foreach ($negativeFilters as $id) {
            DB::table('round5_deactivations')->insert(['table_name' => 'price_filters', 'row_id' => $id]);
        }
        foreach ($winBack as $id) {
            DB::table('round5_deactivations')->insert(['table_name' => 'coupons', 'row_id' => $id]);
        }

        DB::table('price_filters')->whereIn('id', $negativeFilters)->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('coupons')->whereIn('id', $winBack)->update(['is_active' => false, 'updated_at' => now()]);

        // DB::table رویدادهای مدل را رد می‌کند؛ کشِ فیلترها (ApplyPriceFilters) دستی خالی می‌شود.
        Cache::forget('pricing:categories_with_filters');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('supplier_unit_price');
        });

        foreach (DB::table('round5_deactivations')->get() as $row) {
            DB::table($row->table_name)->where('id', $row->row_id)->update(['is_active' => true]);
        }

        Schema::dropIfExists('round5_deactivations');
        Cache::forget('pricing:categories_with_filters');

        Schema::table('coupon_funding_legs', function (Blueprint $table) {
            $table->dropColumn('wallet_code');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('elite_yar_discount_amount');
        });
        // حسابِ کیف پولِ کوپن عمداً می‌ماند: ممکن است تراکنش داشته باشد (دفترِ مالی حذف‌شدنی نیست).
    }
};

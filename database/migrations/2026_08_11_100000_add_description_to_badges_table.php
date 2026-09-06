<?php

use App\Models\Badge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توضیح نشان تا امروز فقط در کدِ فرانت بود (WorkingTogether.jsx) و برای هر نشانِ
 * تازه‌ای که ادمین می‌ساخت، هیچ توضیحی وجود نداشت. حالا ستون خودش را دارد.
 *
 * مقدارهای اولیه همان متن‌های فعلیِ فرانت‌اند تا رفتار سایت عوض نشود.
 */
return new class extends Migration
{
    private const SEED = [
        'gold' => 'تبریک! شما این نشان را دریافت کرده‌اید.',
        'silver' => 'تبریک! شما این نشان را دریافت کرده‌اید.',
        'bronze' => 'تبریک! شما این نشان را دریافت کرده‌اید.',
        'pioneer' => 'تبریک! شما موفق به دریافت این نشان شده‌اید.',
        'serial' => 'تبریک! شما کاربر محصولات گروه نرم‌افزاری سازه هستید.',
        'saze90' => 'تبریک! شما کاربر محصولات گروه نرم‌افزاری سازه هستید.',
    ];

    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->text(Badge::DESCRIPTION)->nullable()->after(Badge::NAME);
        });

        foreach (self::SEED as $code => $description) {
            DB::table('badges')
                ->where(Badge::CODE, $code)
                ->update([Badge::DESCRIPTION => $description]);
        }
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn(Badge::DESCRIPTION);
        });
    }
};

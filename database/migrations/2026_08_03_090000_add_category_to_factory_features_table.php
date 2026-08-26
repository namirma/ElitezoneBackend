<?php

use App\Models\FactoryFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * تفکیکِ دو دسته‌ی کاملاً جدا: ویژگیِ «محصول/تأمین‌کننده» (روی کارتِ محصول)
     * و ویژگیِ «حمل‌ونقل» (روی فوترِ «محاسبه کل هزینه حمل» صفحه‌ی سفارش) — این دو
     * هرگز نباید در یک کارت با هم مخلوط شوند.
     */
    public function up(): void
    {
        Schema::table('factory_features', function (Blueprint $table) {
            $table->string('category')->default(FactoryFeature::CATEGORY_PRODUCT)->after('factory_id');
        });

        // بک‌فیلِ ردیف‌های موجود بر اساس icon_key — هر آیکونی که از قبل به یک دسته تعلق دارد.
        DB::table('factory_features')
            ->whereIn('icon_key', FactoryFeature::SHIPPING_ICON_KEYS)
            ->update(['category' => FactoryFeature::CATEGORY_SHIPPING]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('factory_features', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};

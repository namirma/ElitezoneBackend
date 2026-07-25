<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * یک کاربر = یک سبد.
 *
 * `carts.user_id` هیچ ایندکس یکتایی نداشت و `Cart::firstOrCreate([user_id])`
 * اتمیک نیست، پس دو درخواستِ همزمان (یا merge سبد مهمان) می‌توانستند دو ردیف
 * بسازند. نتیجه‌اش واگراییِ واقعی بود: `CartResolver` سبد را با `firstOrCreate`
 * (کوچک‌ترین id) می‌خواند ولی `PlaceOrderAction` با `latest('updated_at')` —
 * یعنی کاربر سبد A را می‌دید و سفارشِ سبد B ثبت می‌شد.
 *
 * این مایگریشن اول داده را یکدست می‌کند (اقلام و پیوندهای سبدهای اضافی به
 * قدیمی‌ترین سبدِ همان کاربر منتقل و سبدهای اضافی حذف می‌شوند) و بعد ایندکس
 * یکتا را می‌گذارد تا حالتِ دوسبدی دیگر از دیتابیس عبور نکند.
 *
 * توجه: در MySQL ایندکس یکتا روی ستون nullable چند NULL را می‌پذیرد، پس
 * سبدهای مهمان (user_id = NULL، کلیدشان session_id است) دست‌نخورده می‌مانند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateCarts();

        Schema::table('carts', function (Blueprint $table) {
            $table->unique('user_id', 'carts_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_id_unique');
        });
    }

    /**
     * برای هر user_id تکراری: قدیمی‌ترین سبد بازمانده است، بقیه به آن merge
     * و حذف می‌شوند. FKهای cart_items / cart_price_filter_categories /
     * cart_purchase_parameter_value / cart_events همگی cascade هستند، پس هرچه
     * منتقل نشود با حذفِ سبدِ اضافی پاک می‌شود.
     */
    private function mergeDuplicateCarts(): void
    {
        $duplicateUserIds = DB::table('carts')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $cartIds = DB::table('carts')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->pluck('id');

            $survivorId = $cartIds->shift();

            foreach ($cartIds as $duplicateId) {
                $this->moveItems($survivorId, $duplicateId);
                $this->movePivot('cart_price_filter_categories', 'price_filter_category_id', $survivorId, $duplicateId);
                $this->movePivot('cart_purchase_parameter_value', 'purchase_parameter_value_id', $survivorId, $duplicateId);

                DB::table('carts')->where('id', $duplicateId)->delete();
            }
        }
    }

    /**
     * اقلام سبدِ اضافی به سبدِ بازمانده منتقل می‌شوند؛ اگر همان
     * (واریانت، کارخانه، مبدأ) از قبل در سبدِ بازمانده باشد، قلمِ تکراری
     * حذف می‌شود (همان قاعدهٔ CartResolver::mergeGuestCart).
     */
    private function moveItems(int $survivorId, int $duplicateId): void
    {
        $items = DB::table('cart_items')->where('cart_id', $duplicateId)->get();

        foreach ($items as $item) {
            $exists = DB::table('cart_items')
                ->where('cart_id', $survivorId)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('factory_id', $item->factory_id)
                ->where('factory_location_id', $item->factory_location_id)
                ->exists();

            if ($exists) {
                DB::table('cart_items')->where('id', $item->id)->delete();

                continue;
            }

            DB::table('cart_items')->where('id', $item->id)->update(['cart_id' => $survivorId]);
        }
    }

    /**
     * پیوندهای (cart_id, X) — هر پیوندی که در سبدِ بازمانده نیست منتقل
     * می‌شود؛ بقیه حذف تا unique index پیوت نشکند.
     */
    private function movePivot(string $table, string $otherColumn, int $survivorId, int $duplicateId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)->where('cart_id', $duplicateId)->get();

        foreach ($rows as $row) {
            $exists = DB::table($table)
                ->where('cart_id', $survivorId)
                ->where($otherColumn, $row->{$otherColumn})
                ->exists();

            $query = DB::table($table)
                ->where('cart_id', $duplicateId)
                ->where($otherColumn, $row->{$otherColumn});

            $exists ? $query->delete() : $query->update(['cart_id' => $survivorId]);
        }
    }
};

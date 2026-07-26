<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * حذف سبدهای مهمانِ رهاشده.
 *
 * چرا لازم است: `GET /api/cart` پشتِ optional.sanctum است و هر درخواستِ بدونِ
 * کوکیِ cart_session_id یک ردیفِ carts می‌سازد. مرورگرِ واقعی کوکی را نگه
 * می‌دارد و دفعهٔ بعد همان سبد را می‌گیرد، ولی خزندهٔ موتور جستجو / ربات کوکی
 * برنمی‌گرداند — پس تک‌تکِ بازدیدهایشان یک سبدِ یتیم می‌سازد که هیچ‌وقت پاک
 * نمی‌شد. هیچ سیاستِ نگهداشتی برای این جدول وجود نداشت.
 *
 * فقط سبدِ مهمان (user_id = NULL) حذف می‌شود؛ سبدِ کاربرِ لاگین‌شده هرگز.
 */
class PruneGuestCarts extends Command
{
    protected $signature = 'carts:prune-guests {--days= : بازهٔ نگهداشت (پیش‌فرض از config/cart.php)}';

    protected $description = 'حذف سبدهای خرید مهمانِ رهاشده و آیتم‌هایشان';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cart.guest_retention_days', 30));
        $chunk = (int) config('cart.prune_chunk', 1000);

        if ($days < 1) {
            $this->error('بازهٔ نگهداشت باید حداقل ۱ روز باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // updated_at مبناست نه created_at: سبدی که کاربر مهمان هنوز دارد رویش کار
        // می‌کند نباید وسطِ خرید حذف شود.
        $ids = Cart::query()
            ->whereNull(Cart::USER_ID)
            ->where('updated_at', '<', $cutoff)
            ->orderBy(Cart::ID)
            ->limit($chunk)
            ->pluck(Cart::ID);

        if ($ids->isEmpty()) {
            $this->info('سبد مهمانِ منقضی‌ای برای حذف وجود ندارد.');

            return self::SUCCESS;
        }

        // آیتم‌ها صریحاً حذف می‌شوند — به cascade در سطح دیتابیس تکیه نمی‌کنیم
        // چون در همهٔ محیط‌ها تضمین‌شده نیست.
        DB::transaction(function () use ($ids) {
            CartItem::whereIn('cart_id', $ids)->delete();
            Cart::whereIn(Cart::ID, $ids)->delete();
        });

        $message = "حذف {$ids->count()} سبد مهمانِ قدیمی‌تر از {$days} روز.";
        $this->info($message);
        Log::info('PruneGuestCarts: '.$message);

        return self::SUCCESS;
    }
}

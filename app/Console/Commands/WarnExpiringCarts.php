<?php

namespace App\Console\Commands;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Cart;
use App\Services\Cart\CartParkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * پیامکِ «۳۰ دقیقه تا پایان مهلت سبد» — بند ۳.۱ و ۱۰.۳.
 *
 * چرا کرون و نه `SendSmsJob::dispatch()->delay()`: بند ۱۰.۱ سند می‌گوید برای
 * هشدارهای **قابل‌تمدید** باید از کران استفاده شود، چون یک job زمان‌بندی‌شده را
 * نمی‌توان لغو کرد — کاربری که سفارشش را ثبت کرده و سبدش پاک شده، همچنان
 * پیامکِ «سبدت دارد منقضی می‌شود» می‌گرفت.
 *
 * `park_warned_at` گاردِ ارسالِ دوباره است: کرون هر ۵ دقیقه اجرا می‌شود و بدون
 * آن، در پنجره‌ی ۳۰ دقیقه‌ای شش پیامک به یک نفر می‌رفت.
 */
class WarnExpiringCarts extends Command
{
    protected $signature = 'carts:warn-expiring {--limit=500 : سقف سبد در هر اجرا}';

    protected $description = 'ارسال پیامک هشدار به کاربرانی که مهلت سبدشان رو به پایان است';

    public function handle(CartParkService $parkService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $lead = CartParkService::warningLeadMinutes();

        $carts = Cart::query()
            ->with('user')
            ->whereNotNull(Cart::USER_ID)
            ->whereNotNull(Cart::PARKED_UNTIL)
            ->whereNull(Cart::PARK_WARNED_AT)
            ->where(Cart::PARKED_UNTIL, '>', now())
            ->where(Cart::PARKED_UNTIL, '<=', now()->addMinutes($lead))
            ->orderBy(Cart::ID)
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($carts as $cart) {
            // `shouldWarn` همان قاعده‌ی «اگر park_time < 30 هشدار نده» را هم
            // اعمال می‌کند؛ کوئری بالا فقط پنجره را تنگ می‌کند.
            if (! $parkService->shouldWarn($cart)) {
                continue;
            }

            $mobile = $cart->user?->mobile;

            if (! $mobile) {
                continue;
            }

            try {
                SendSmsJob::template($mobile, 'cart_expiring', [
                    'minutes' => $lead,
                ]);

                // پیش از dispatch علامت نمی‌زنیم و پس از آن می‌زنیم: اگر صف
                // بالا نیاید، اجرای بعدی دوباره تلاش می‌کند.
                $cart->forceFill([Cart::PARK_WARNED_AT => now()])->save();

                $sent++;
            } catch (\Throwable $e) {
                Log::error('carts:warn-expiring failed for cart '.$cart->id.': '.$e->getMessage());
            }
        }

        $this->info("پیامک هشدار ارسال‌شده: {$sent}");

        return self::SUCCESS;
    }
}

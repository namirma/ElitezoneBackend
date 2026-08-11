<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Services\Cart\CartParkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * حذفِ سبدهایی که مهلتشان تمام شده، پس از نوشتنِ اسنپ‌شات — بند ۳.۱ و ۱۰.۳.
 *
 * ترتیب حیاتی است: **اول اسنپ‌شات، بعد حذف**، و هر دو در یک تراکنش. اگر حذف
 * موفق شود و اسنپ‌شات نه، سبد بی‌صدا از بین می‌رود و گزارشِ «سبد رهاشده» —
 * تنها سیگنالِ ازدست‌رفتنِ فروش — همان ردیف را برای همیشه از دست می‌دهد.
 *
 * فقط سبدِ کاربرِ واردشده؛ سبدِ مهمان `parked_until` ندارد و مسیرِ خودش
 * (`carts:prune-guests`) را دارد.
 */
class ExpireParkedCarts extends Command
{
    protected $signature = 'carts:expire-parked {--limit=500 : سقف سبد در هر اجرا}';

    protected $description = 'اسنپ‌شات و حذف سبدهای خریدی که مهلت نگهداری‌شان تمام شده';

    public function handle(CartParkService $parkService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $carts = Cart::query()
            ->whereNotNull(Cart::USER_ID)
            ->whereNotNull(Cart::PARKED_UNTIL)
            ->where(Cart::PARKED_UNTIL, '<=', now())
            // بند ۳.۲: «سبدهای منتظر تا لحظه‌ی اعلام قیمت جدید زنده می‌مانند.»
            //
            // اگر اعتبارِ قیمتِ تأمین‌کننده تمام شده و قیمتِ تازه‌ای اعلام نشده،
            // سبد در حالتِ انتظار است و نباید حذف شود — کاربر منتظرِ چیزی است
            // که هنوز نیامده، پس منقضی‌کردنش او را بابت تأخیرِ تأمین‌کننده
            // جریمه می‌کند. لحظه‌ی اعلام، `reparkWaitingCartsFor` مهلتِ تازه
            // می‌دهد و پیامک می‌فرستد.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('supplier_profiles')
                    ->whereColumn('supplier_profiles.user_id', 'carts.supplier_user_id')
                    ->where(function ($inner) {
                        $inner->whereNull('supplier_profiles.reserve_time')
                            ->orWhere('supplier_profiles.reserve_time', '<=', now());
                    });
            })
            ->orderBy(Cart::ID)
            ->limit($limit)
            ->get();

        $expired = 0;

        foreach ($carts as $cart) {
            try {
                DB::transaction(function () use ($cart, $parkService, &$expired) {
                    /** @var Cart|null $locked */
                    $locked = Cart::whereKey($cart->id)->lockForUpdate()->first();

                    // زیر قفل دوباره بررسی می‌شود: کاربر ممکن است در همین فاصله
                    // سفارش ثبت کرده باشد (که سبد را پاک می‌کند) یا سبد را خالی
                    // کرده باشد (که مهلت را برمی‌دارد).
                    if (! $locked || $locked->{Cart::PARKED_UNTIL} === null
                        || $locked->{Cart::PARKED_UNTIL}->isFuture()) {
                        return;
                    }

                    $parkService->snapshot($locked);

                    $locked->items()->delete();
                    $locked->priceFilterCategories()->detach();
                    $locked->purchaseParameters()->detach();
                    $locked->delete();

                    $expired++;
                });
            } catch (\Throwable $e) {
                // یک سبدِ خراب نباید بقیه را متوقف کند.
                Log::error('carts:expire-parked failed for cart '.$cart->id.': '.$e->getMessage());
            }
        }

        $this->info("سبدهای منقضی‌شده: {$expired}");

        return self::SUCCESS;
    }
}

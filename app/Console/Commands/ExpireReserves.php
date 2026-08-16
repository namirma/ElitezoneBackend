<?php

namespace App\Console\Commands;

use App\Actions\Order\CancelOrderAction;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * لغوِ رزروهایی که مهلت **و** ارفاقشان تمام شده — بند ۳.۴ و ۱۰.۳.
 *
 * ارفاق از اسنپ‌شاتِ خودِ سفارش خوانده می‌شود (`orders.reserve_grace_hours`) و
 * نه از تنظیمِ سراسری (الحاقیه‌ی ۱، تغییر ۳): اگر تأمین‌کننده پس از ثبتِ یک
 * رزرو مقدار را عوض کند، مهلتِ آن رزرو نباید بی‌صدا جابه‌جا شود.
 *
 * لغو از `CancelOrderAction` می‌گذرد تا بیعانه کامل برگردد و موجودی آزاد شود —
 * یک `update([STATUS => cancelled])` خام پول و کالا را هر دو گیر می‌انداخت.
 */
class ExpireReserves extends Command
{
    protected $signature = 'orders:expire-reserves {--limit=200 : سقف سفارش در هر اجرا}';

    protected $description = 'لغو رزروهایی که مهلت و مهلت ارفاقشان گذشته است';

    public function handle(CancelOrderAction $cancel): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // فیلترِ درشت در SQL (فقط آن‌هایی که مهلتشان گذشته)، و بررسیِ دقیقِ
        // ارفاق در PHP — چون ساعتِ ارفاق per-order است و در SQL به یک
        // `DATE_ADD` با ستونِ متغیر نیاز داشت که روی هر دو شاخه یکسان نیست.
        $candidates = Order::query()
            ->where(Order::STATUS, Order::STATUS_RESERVED)
            ->whereNotNull(Order::RESERVE_DEADLINE)
            ->where(Order::RESERVE_DEADLINE, '<=', now())
            ->orderBy(Order::ID)
            ->limit($limit)
            ->get();

        $cancelled = 0;

        /** @var Order $order */
        foreach ($candidates as $order) {
            $grace = (int) ($order->{Order::RESERVE_GRACE_HOURS} ?? 0);

            if ($order->{Order::RESERVE_DEADLINE}->copy()->addHours($grace)->isFuture()) {
                continue; // هنوز در بازه‌ی ارفاق است
            }

            try {
                $cancel->execute($order, __('orders.reserve_expired_cancelled'), true, true);
                $cancelled++;
            } catch (\Throwable $e) {
                // یک رزروِ خراب نباید بقیه را متوقف کند.
                Log::channel('financial')->error(
                    'orders:expire-reserves failed for order '.$order->id.': '.$e->getMessage()
                );
            }
        }

        $this->info("رزروهای لغوشده: {$cancelled}");

        return self::SUCCESS;
    }
}

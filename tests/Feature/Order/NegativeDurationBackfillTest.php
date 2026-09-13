<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پاک‌سازیِ `duration_minutes`ِ منفی — مهاجرتِ
 * `2026_09_12_120000_fix_negative_order_status_history_durations`.
 *
 * باگِ ریشه‌ای در `OrderObserver` رفع شد (Carbon 3 عددِ علامت‌دار می‌دهد و
 * ترتیبِ عملوندها برعکس بود)، ولی آن فقط جلوی ردیف‌های **تازه** را می‌گیرد.
 * این تست خودِ عملیاتِ پاک‌سازی را pin می‌کند.
 *
 * چرا مهاجرت را مستقیم صدا نمی‌زنیم: سوئیت روی دیتابیسِ کاملاً مایگریت‌شده اجرا
 * می‌شود، پس مهاجرت از قبل اجرا شده و دوباره اجرا نمی‌شود. این‌جا **همان عبارتِ
 * SQL** را روی داده‌ی ساختگی اجرا می‌کنیم — یعنی همان چیزی که مهاجرت انجام
 * می‌دهد، نه یک بازنویسیِ تقریبی.
 */
class NegativeDurationBackfillTest extends TestCase
{
    use DatabaseTransactions;

    /** همان به‌روزرسانی‌ای که مهاجرت اجرا می‌کند. */
    private function runBackfill(): int
    {
        return DB::table('order_status_histories')
            ->where(OrderStatusHistory::DURATION_MINUTES, '<', 0)
            ->update([
                OrderStatusHistory::DURATION_MINUTES => DB::raw(
                    'ABS('.OrderStatusHistory::DURATION_MINUTES.')'
                ),
            ]);
    }

    private function history(Order $order, ?int $duration): int
    {
        return DB::table('order_status_histories')->insertGetId([
            OrderStatusHistory::ORDER_ID => $order->id,
            OrderStatusHistory::FROM_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::TO_STATUS => Order::STATUS_SHIPPED,
            OrderStatusHistory::USER_ID => null,
            OrderStatusHistory::DURATION_MINUTES => $duration,
            OrderStatusHistory::CREATED_AT => now(),
        ]);
    }

    private function durationOf(int $id): ?int
    {
        $value = DB::table('order_status_histories')
            ->where('id', $id)
            ->value(OrderStatusHistory::DURATION_MINUTES);

        return $value === null ? null : (int) $value;
    }

    #[Test]
    public function a_negative_duration_becomes_its_positive_magnitude(): void
    {
        $order = Order::factory()->create();
        $id = $this->history($order, -2979);

        $this->runBackfill();

        $this->assertSame(
            2979,
            $this->durationOf($id),
            'مدتِ منفی باید به قدرِ مطلقِ خودش تبدیل شود — قدرِ عدد از اول درست بوده، فقط علامت وارونه بود.'
        );
    }

    /**
     * مهم‌ترین حالت: ردیف‌های سالم نباید لمس شوند.
     *
     * ‏`0` عمدی است — آبزرور برای رویدادِ `created` و وقتی تاریخچهٔ قبلی نیست
     * صفر می‌نویسد، و آن «نامشخص» نیست بلکه «هیچ مدتِ قبلی‌ای وجود نداشت».
     */
    #[Test]
    public function positive_zero_and_null_rows_are_left_alone(): void
    {
        $order = Order::factory()->create();
        $positive = $this->history($order, 1407);
        $zero = $this->history($order, 0);
        $null = $this->history($order, null);

        $this->runBackfill();

        $this->assertSame(1407, $this->durationOf($positive), 'ردیفِ مثبت نباید تغییر کند.');
        $this->assertSame(0, $this->durationOf($zero), 'صفر معنا دارد و نباید تغییر کند.');
        $this->assertNull($this->durationOf($null), 'NULL نباید به عدد تبدیل شود.');
    }

    /** اجرای دوباره باید بی‌اثر باشد — مهاجرت روی هر محیطی ممکن است دوباره اجرا شود. */
    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $order = Order::factory()->create();
        $id = $this->history($order, -45);

        $this->runBackfill();
        $after = $this->durationOf($id);

        $second = $this->runBackfill();

        $this->assertSame(0, $second, 'اجرای دوم نباید هیچ ردیفی را لمس کند.');
        $this->assertSame($after, $this->durationOf($id), 'مقدار پس از اجرای دوم باید همان بماند.');
    }

    /**
     * ⚠️ نگهبانِ اصلی: هیچ ردیفِ منفی‌ای نباید **دوباره** ساخته شود.
     *
     * اگر کسی ترتیبِ عملوندهای `OrderObserver` را برگرداند، این تست قرمز می‌شود —
     * یعنی پاک‌سازیِ داده و رفعِ علتِ ریشه‌ای با هم pin شده‌اند، نه جدا.
     */
    #[Test]
    public function the_observer_no_longer_produces_negative_durations(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PROCESSING]);

        $this->travel(20)->minutes();
        $order->update([Order::STATUS => Order::STATUS_SHIPPED]);

        $this->travel(7)->minutes();
        $order->update([Order::STATUS => Order::STATUS_DELIVERED]);

        $this->travelBack();

        $negatives = DB::table('order_status_histories')
            ->where(OrderStatusHistory::ORDER_ID, $order->id)
            ->where(OrderStatusHistory::DURATION_MINUTES, '<', 0)
            ->count();

        $this->assertSame(0, $negatives, 'آبزرور نباید هیچ مدتِ منفی بنویسد.');
    }
}

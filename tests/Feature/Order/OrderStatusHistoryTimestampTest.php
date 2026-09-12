<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `order_status_histories.created_at` باید با ساعتِ **PHP** نوشته شود، نه دیتابیس.
 *
 * پیشینه: مدل `$timestamps = false` دارد و `OrderObserver` صریحاً `now()` می‌فرستد،
 * ولی `created_at` در `$fillable` نبود — پس mass-assignment آن را بی‌صدا دور می‌ریخت
 * و ستون از `DEFAULT CURRENT_TIMESTAMP` مایگریشن پر می‌شد. چون `config('app.timezone')`
 * برابر UTC است ولی `@@session.time_zone` مای‌اس‌کیوال `SYSTEM` است، هر ردیفِ تاریخچه
 * به‌اندازهٔ آفستِ سرور با بقیهٔ رویدادها جابه‌جا می‌شد و در خطِ زمانیِ سفارش، «تغییر
 * وضعیت» پیش از «ثبت سفارش» ظاهر می‌شد.
 *
 * این تست خودِ قرارداد را pin می‌کند، نه فقط نشانه‌اش را — تا اگر کسی `created_at` را
 * دوباره از `$fillable` بردارد، اینجا سرخ شود و نه در یک باگِ نمایشیِ مبهم.
 */
class OrderStatusHistoryTimestampTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function created_at_is_mass_assignable(): void
    {
        $this->assertContains(
            OrderStatusHistory::CREATED_AT,
            (new OrderStatusHistory)->getFillable(),
            'created_at باید fillable باشد وگرنه مقدارِ OrderObserver دور ریخته می‌شود.'
        );
    }

    #[Test]
    public function the_observer_timestamp_wins_over_the_database_default(): void
    {
        $order = Order::factory()->create();

        $history = OrderStatusHistory::where(OrderStatusHistory::ORDER_ID, $order->id)
            ->orderBy(OrderStatusHistory::CREATED_AT)
            ->first();

        $this->assertNotNull($history, 'OrderObserver باید ردیفِ تاریخچهٔ اولیه را بسازد.');

        // هر دو ستون باید از یک ساعت آمده باشند. اگر تاریخچه از
        // DEFAULT CURRENT_TIMESTAMP پر شود، این اختلاف برابرِ آفستِ tz سرور می‌شود
        // (روی این ماشین ۵ ساعت، روی سرور ایران ۳٫۵ ساعت) — نه چند ثانیه.
        $skew = abs(
            $history->{OrderStatusHistory::CREATED_AT}->diffInSeconds($order->created_at)
        );

        $this->assertLessThan(
            60,
            $skew,
            "زمانِ تاریخچه و زمانِ سفارش {$skew} ثانیه فاصله دارند — یعنی از دو ساعتِ متفاوت آمده‌اند."
        );
    }

    #[Test]
    public function a_status_change_also_records_a_php_side_timestamp(): void
    {
        $order = Order::factory()->create();

        $order->update([Order::STATUS => Order::STATUS_PROCESSING]);

        $latest = OrderStatusHistory::where(OrderStatusHistory::ORDER_ID, $order->id)
            ->orderByDesc(OrderStatusHistory::CREATED_AT)
            ->first();

        $this->assertSame(Order::STATUS_PROCESSING, $latest->{OrderStatusHistory::TO_STATUS});

        $phpNow = now();
        $skew = abs($latest->{OrderStatusHistory::CREATED_AT}->diffInSeconds($phpNow));

        $this->assertLessThan(
            60,
            $skew,
            "ردیفِ تغییرِ وضعیت {$skew} ثانیه با ساعتِ PHP فاصله دارد — ستون از ساعتِ دیتابیس پر شده است."
        );
    }

    /**
     * `duration_minutes` باید مدتِ **سپری‌شده** باشد — عددِ مثبت.
     *
     * پیشینه: در Carbon 2 (لاراول ۱۰) `diffIn*` قدرمطلقِ صحیح برمی‌گرداند، در
     * Carbon 3 (لاراول ۱۳) عددِ علامت‌دارِ اعشاری. `OrderObserver` می‌نوشت
     * `now()->diffInMinutes($lastHistory->created_at)` — یعنی «از now تا گذشته» —
     * که روی L13 منفی می‌شود. ستون integer است پس مقدار هم بریده می‌شد، و
     * `OrderTimelineService` همان را به کاربر «−۳۰ دقیقه در وضعیت قبلی» نشان می‌داد.
     *
     * این تست خودِ علامت را pin می‌کند، نه صرفاً «ستون پر شده» — با ترتیبِ غلطِ
     * عملوندها سرخ می‌شود.
     */
    #[Test]
    public function the_duration_of_a_status_change_is_a_positive_elapsed_time(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PROCESSING]);

        // ساعتِ تست را جلو می‌بریم تا بینِ رکوردِ اولیه (created) و تغییرِ وضعیت
        // واقعاً زمان گذشته باشد؛ وگرنه صفر و منفیِ صفر از هم تفکیک نمی‌شوند.
        Carbon::setTestNow(now()->addMinutes(45));

        $order->update([Order::STATUS => Order::STATUS_DELIVERED]);

        $duration = OrderStatusHistory::where(OrderStatusHistory::ORDER_ID, $order->id)
            ->latest(OrderStatusHistory::CREATED_AT)
            ->value(OrderStatusHistory::DURATION_MINUTES);

        Carbon::setTestNow();

        $this->assertSame(
            45,
            (int) $duration,
            'مدتِ توقف باید ۴۵ دقیقهٔ مثبت باشد. مقدارِ منفی یعنی ترتیبِ عملوندهای '
            .'diffInMinutes برعکس است و Carbon 3 علامت‌دار برمی‌گرداند.'
        );
    }

    #[Test]
    public function the_migration_default_is_not_what_fills_the_column(): void
    {
        // اثباتِ مستقیم: اگر ستون از دیتابیس پر می‌شد، مقدارِ صریحی که اینجا
        // می‌گذاریم نادیده گرفته می‌شد.
        $order = Order::factory()->create();
        $explicit = now()->subDays(3)->startOfMinute();

        $row = OrderStatusHistory::create([
            OrderStatusHistory::ORDER_ID => $order->id,
            OrderStatusHistory::FROM_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::TO_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::USER_ID => null,
            OrderStatusHistory::DURATION_MINUTES => 0,
            OrderStatusHistory::CREATED_AT => $explicit,
        ]);

        $stored = DB::table('order_status_histories')
            ->where('id', $row->id)
            ->value('created_at');

        $this->assertSame(
            $explicit->toDateTimeString(),
            Carbon::parse($stored)->toDateTimeString(),
            'مقدارِ صریحِ created_at باید ذخیره شود، نه DEFAULT CURRENT_TIMESTAMP.'
        );
    }
}

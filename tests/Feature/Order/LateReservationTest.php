<?php

namespace Tests\Feature\Order;

use App\Models\Cart;
use App\Models\Setting;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Payment\ReserveWarningResolver;
use App\Services\Sales\ReserveDeadlineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * رزروِ دیرهنگام — الحاقیه‌ی ۱، تغییر ۱ و ۲.
 *
 * دو متغیرِ **مستقل** که مدام با هم اشتباه گرفته می‌شوند:
 *   `reserve_auto_extend_minutes` (تأمین‌کننده) → چقدر وقت بده
 *   `reserve_warning_threshold_minutes` (بک‌آفیس) → کِی حرف بزن
 */
class LateReservationTest extends TestCase
{
    use DatabaseTransactions;

    private function supplierWith(?int $autoExtendMinutes, $reserveTime): SupplierProfile
    {
        $user = User::factory()->create();

        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $user->id]);
        $profile->forceFill([
            SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
            SupplierProfile::RESERVE_TIME => $reserveTime,
            SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES => $autoExtendMinutes ?? 0,
        ])->save();

        return $profile->fresh();
    }

    private function resolver(): ReserveDeadlineResolver
    {
        Cache::forget(Setting::CACHE_KEY);

        return app(ReserveDeadlineResolver::class);
    }

    // ── الف) مهلتِ رزرو ─────────────────────────────────────────────────────

    #[Test]
    public function plenty_of_time_left_means_the_deadline_is_the_price_window(): void
    {
        $reserveTime = now()->addHours(10)->startOfSecond();
        $supplier = $this->supplierWith(autoExtendMinutes: 60, reserveTime: $reserveTime);

        $this->assertSame(
            $reserveTime->toIso8601String(),
            $this->resolver()->resolve($supplier)->toIso8601String(),
        );
    }

    #[Test]
    public function less_time_left_than_the_auto_extend_pushes_the_deadline_out(): void
    {
        $supplier = $this->supplierWith(autoExtendMinutes: 60, reserveTime: now()->addMinutes(10));

        $deadline = $this->resolver()->resolve($supplier);

        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $deadline->timestamp, 60);
    }

    #[Test]
    public function a_zero_auto_extend_gives_no_margin_at_all(): void
    {
        // پیش‌فرضِ صفر عمدی است (الحاقیه، نکته‌ی ۲): خریداری که یک دقیقه مانده
        // رزرو کند، یک دقیقه فرصت دارد. تأمین‌کننده‌ای که حاشیه می‌خواهد، خودش
        // عدد را بالا می‌برد.
        $reserveTime = now()->addMinute()->startOfSecond();
        $supplier = $this->supplierWith(autoExtendMinutes: 0, reserveTime: $reserveTime);

        $this->assertSame(
            $reserveTime->toIso8601String(),
            $this->resolver()->resolve($supplier)->toIso8601String(),
        );
    }

    #[Test]
    public function the_auto_extend_never_moves_the_suppliers_reserve_time(): void
    {
        // نکته‌ی ۱ الحاقیه: `reserve_time` سراسری است و روی سبدِ همه‌ی مشتریان
        // اثر دارد — یک رزرو نباید جابه‌جایش کند.
        $reserveTime = now()->addMinutes(5)->startOfSecond();
        $supplier = $this->supplierWith(autoExtendMinutes: 120, reserveTime: $reserveTime);

        $this->resolver()->resolve($supplier);

        $this->assertSame(
            $reserveTime->toIso8601String(),
            $supplier->fresh()->{SupplierProfile::RESERVE_TIME}->toIso8601String(),
        );
    }

    #[Test]
    public function an_expired_price_window_yields_no_deadline_at_all(): void
    {
        // شکافی که در بازبینی پیدا شد: با `autoExtend = 0` و `reserve_time`ِ
        // گذشته، فرمولِ خامِ الحاقیه مهلتی در **گذشته** می‌داد — رزروی که همان
        // لحظه‌ی تولد مرده است. `null` یعنی «اصلاً قابل رزرو نیست».
        $supplier = $this->supplierWith(autoExtendMinutes: 0, reserveTime: now()->subMinute());

        $this->assertNull($this->resolver()->resolve($supplier));
    }

    #[Test]
    public function an_expired_window_is_not_rescued_by_a_generous_auto_extend(): void
    {
        $supplier = $this->supplierWith(autoExtendMinutes: 600, reserveTime: now()->subMinute());

        $this->assertNull($this->resolver()->resolve($supplier));
    }

    // ── ب) هشدار — کاملاً مستقل از الف ──────────────────────────────────────

    #[Test]
    public function the_warning_threshold_does_not_touch_the_deadline(): void
    {
        // نکته‌ی ۳ الحاقیه: آستانه فقط می‌گوید کِی پیام بده.
        Setting::updateOrCreate(
            [Setting::KEY => 'reserve_warning_threshold_minutes'],
            [Setting::VALUE => '600']
        );
        Cache::forget(Setting::CACHE_KEY);

        $reserveTime = now()->addMinutes(30)->startOfSecond();
        $supplier = $this->supplierWith(autoExtendMinutes: 0, reserveTime: $reserveTime);

        $this->assertSame(
            $reserveTime->toIso8601String(),
            $this->resolver()->resolve($supplier)->toIso8601String(),
            'آستانه‌ی هشدار نباید مهلت را جابه‌جا کند.',
        );

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplier->{SupplierProfile::USER_ID}])->save();

        $this->assertTrue(app(ReserveWarningResolver::class)->forCart($cart->fresh())['show']);
    }
}

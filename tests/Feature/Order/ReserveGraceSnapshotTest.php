<?php

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Sales\ReserveDeadlineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اسنپ‌شاتِ مهلتِ ارفاق — الحاقیه‌ی ۱، تغییر ۳.
 *
 * الگو همان `delivery_deadline_hours` است: پیش‌فرضِ بک‌آفیس → override
 * تأمین‌کننده → اسنپ‌شات روی سفارش. و دلیلِ اسنپ‌شات همان دلیلی است که کد از
 * قبل برای `vat_rate` و نرخ‌های کمیسیون دارد: تغییرِ بعدیِ تأمین‌کننده نباید
 * مهلتِ رزروی که از قبل ثبت شده را بی‌صدا جابه‌جا کند.
 */
class ReserveGraceSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private function setBackofficeGrace(int $hours): void
    {
        Setting::updateOrCreate(
            [Setting::KEY => 'reserve_grace_hours'],
            [Setting::VALUE => (string) $hours]
        );
        Cache::forget(Setting::CACHE_KEY);
    }

    private function supplierWithGrace(?int $graceHours): SupplierProfile
    {
        $user = User::factory()->create();

        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $user->id]);
        $profile->forceFill([
            SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
            SupplierProfile::RESERVE_TIME => now()->addDays(2),
            SupplierProfile::RESERVE_GRACE_HOURS => $graceHours,
        ])->save();

        return $profile->fresh();
    }

    private function resolver(): ReserveDeadlineResolver
    {
        Cache::forget(Setting::CACHE_KEY);

        return app(ReserveDeadlineResolver::class);
    }

    private function reservedOrder(int $graceHours, $deadline): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'GRC-'.Str::random(10),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        $order->forceFill([
            Order::RESERVE_DEADLINE => $deadline,
            Order::RESERVE_DEADLINE_ORIGINAL => $deadline,
            Order::RESERVE_GRACE_HOURS => $graceHours,
        ])->save();

        return $order->fresh();
    }

    #[Test]
    public function without_a_supplier_override_the_backoffice_default_is_used(): void
    {
        $this->setBackofficeGrace(5);

        $this->assertSame(5, $this->resolver()->graceHoursFor($this->supplierWithGrace(null)));
    }

    #[Test]
    public function a_supplier_override_wins_over_the_backoffice_default(): void
    {
        $this->setBackofficeGrace(5);

        $this->assertSame(12, $this->resolver()->graceHoursFor($this->supplierWithGrace(12)));
    }

    #[Test]
    public function a_supplier_override_of_zero_is_honoured_not_treated_as_unset(): void
    {
        // صفر یعنی «هیچ ارفاقی نده» و یک انتخابِ معتبر است — با `?:` به‌جای
        // `??` این بی‌صدا به پیش‌فرضِ بک‌آفیس می‌افتاد.
        $this->setBackofficeGrace(5);

        $this->assertSame(0, $this->resolver()->graceHoursFor($this->supplierWithGrace(0)));
    }

    #[Test]
    public function changing_the_supplier_value_after_a_reservation_does_not_move_its_deadline(): void
    {
        $this->setBackofficeGrace(5);
        $supplier = $this->supplierWithGrace(3);

        $order = $this->reservedOrder(
            graceHours: $this->resolver()->graceHoursFor($supplier),
            deadline: now()->addHour(),
        );

        $this->assertSame(3, (int) $order->{Order::RESERVE_GRACE_HOURS});

        // تأمین‌کننده بعداً نظرش عوض می‌شود.
        $supplier->update([SupplierProfile::RESERVE_GRACE_HOURS => 48]);

        $this->assertSame(3, (int) $order->fresh()->{Order::RESERVE_GRACE_HOURS},
            'اسنپ‌شات سفارش نباید با تغییر بعدی تأمین‌کننده جابه‌جا شود.');
    }

    #[Test]
    public function expiry_reads_the_orders_snapshot_not_the_global_setting(): void
    {
        // تنظیمِ سراسری سخاوتمند است، ولی اسنپ‌شاتِ خودِ سفارش صفر — پس این
        // رزرو باید لغو شود. اگر کرون تنظیمِ سراسری را می‌خواند، زنده می‌ماند.
        $this->setBackofficeGrace(72);

        $order = $this->reservedOrder(graceHours: 0, deadline: now()->subMinute());

        $cancel = Mockery::mock(CancelOrderAction::class);
        $cancel->shouldReceive('execute')->once();
        $this->app->instance(CancelOrderAction::class, $cancel);

        $this->artisan('orders:expire-reserves')->assertSuccessful();
    }

    #[Test]
    public function a_reservation_still_inside_its_own_grace_window_survives(): void
    {
        $this->setBackofficeGrace(0);

        $this->reservedOrder(graceHours: 24, deadline: now()->subHour());

        $cancel = Mockery::mock(CancelOrderAction::class);
        $cancel->shouldReceive('execute')->never();
        $this->app->instance(CancelOrderAction::class, $cancel);

        $this->artisan('orders:expire-reserves')->assertSuccessful();
    }
}

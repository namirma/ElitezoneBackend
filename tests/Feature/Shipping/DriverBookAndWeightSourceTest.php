<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Exceptions\BusinessException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Weighbridge\ManualEntryAdapter;
use App\Services\Weighbridge\WeightSourceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دفترچه‌ی رانندگان (بند ۳.۹ / ۴.۶) و منابع وزن (بند ۳.۷).
 *
 * هر دو در ممیزی «پیاده‌نشده» بودند: نه جدول `drivers` وجود داشت، نه رابطِ
 * `WeightSourceInterface` و سه adapterش، در حالی که §۶.۱ هر دو را صریح نام
 * برده بود.
 */
class DriverBookAndWeightSourceTest extends TestCase
{
    use DatabaseTransactions;

    private User $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carrier = User::factory()->create();
    }

    private function makeDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            Driver::CARRIER_USER_ID => $this->carrier->id,
            Driver::FULL_NAME => 'رضا محمدی',
            Driver::MOBILE => '0912'.random_int(1000000, 9999999),
            Driver::PLATE_NUMBER => '۱۲ ب ۳۴۵ ایران ۱۰',
        ], $overrides));
    }

    // ── دفترچه‌ی رانندگان ────────────────────────────────────────────────────

    #[Test]
    public function a_driver_is_not_verified_until_the_otp_is_confirmed(): void
    {
        $driver = $this->makeDriver();

        $this->assertFalse($driver->isMobileVerified(),
            'بند ۳.۹: موبایل باید با OTP احراز شود، پس ثبتِ خام نباید احرازشده باشد');
    }

    /**
     * ‏`mobile_verified_at` عمداً در `$fillable` نیست — وگرنه یک
     * `update($request->all())` می‌توانست راننده‌ی احرازنشده را احرازشده جا بزند.
     */
    #[Test]
    public function the_verification_stamp_cannot_be_mass_assigned(): void
    {
        $driver = $this->makeDriver();

        $driver->update([Driver::MOBILE_VERIFIED_AT => now()]);

        $this->assertFalse($driver->fresh()->isMobileVerified());
    }

    #[Test]
    public function the_same_mobile_can_serve_two_different_carriers(): void
    {
        $mobile = '09121110000';
        $this->makeDriver([Driver::MOBILE => $mobile]);

        $other = User::factory()->create();
        $second = Driver::create([
            Driver::CARRIER_USER_ID => $other->id,
            Driver::FULL_NAME => 'رضا محمدی',
            Driver::MOBILE => $mobile,
        ]);

        // یکتایی per-حمل‌کننده است و نه سراسری: یک راننده می‌تواند برای چند
        // شرکت کار کند.
        $this->assertNotSame($second->id, Driver::where(Driver::CARRIER_USER_ID, $this->carrier->id)->first()?->id);
        $this->assertSame(2, Driver::where(Driver::MOBILE, $mobile)->count());
    }

    #[Test]
    public function the_same_mobile_twice_for_one_carrier_is_refused(): void
    {
        $mobile = '09121110001';
        $this->makeDriver([Driver::MOBILE => $mobile]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeDriver([Driver::MOBILE => $mobile]);
    }

    // ── منابع وزن ────────────────────────────────────────────────────────────

    private function makeShipment(float $plannedWeight): Shipment
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->carrier->id,
            Order::ORDER_NUMBER => 'TEST-WS-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
        ]);

        return Shipment::create([
            Shipment::ORDER_ID => $order->id,
            Shipment::WEIGHT => $plannedWeight,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function manual_entry_is_the_only_source_enabled_in_v1(): void
    {
        $resolver = app(WeightSourceResolver::class);

        $this->assertSame(['manual'], $resolver->availableCodes(),
            'بند ۳.۷: ManualEntryAdapter تنها adapter فعال نسخه‌ی اول است');
    }

    #[Test]
    public function a_skeleton_source_refuses_instead_of_inventing_a_weight(): void
    {
        // ‏۵۰۳ و نه یک عددِ ساختگی: وزنِ جعلی مستقیم وارد تعدیل مالی می‌شد.
        $this->expectException(BusinessException::class);
        app(WeightSourceResolver::class)->for('ocr');
    }

    #[Test]
    public function an_unknown_source_code_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        app(WeightSourceResolver::class)->for('carrier-pigeon');
    }

    #[Test]
    public function manual_entry_accepts_a_plausible_weight(): void
    {
        $shipment = $this->makeShipment(1000);

        $weight = app(ManualEntryAdapter::class)->readWeight($shipment, ['actual_weight' => 1120]);

        $this->assertSame(1120.0, $weight);
    }

    #[Test]
    public function manual_entry_rejects_a_non_positive_weight(): void
    {
        $shipment = $this->makeShipment(1000);

        $this->expectException(BusinessException::class);
        app(ManualEntryAdapter::class)->readWeight($shipment, ['actual_weight' => 0]);
    }

    /**
     * گاردِ خطای تایپی: یک صفرِ اضافه (۱۰۰۰ → ۱۰۰۰۰۰) در غیر این صورت مستقیم
     * وارد `WeighbridgeReconciler` می‌شد. سقفِ اضافه‌بارِ سند این را نمی‌گیرد
     * چون آن روی **کلِ سفارش** است نه محموله‌ی منفرد.
     */
    #[Test]
    public function manual_entry_rejects_a_weight_that_is_wildly_off(): void
    {
        $shipment = $this->makeShipment(1000);

        $this->expectException(BusinessException::class);
        app(ManualEntryAdapter::class)->readWeight($shipment, ['actual_weight' => 100_000]);
    }
}

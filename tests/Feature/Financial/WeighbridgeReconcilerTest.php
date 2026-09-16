<?php

namespace Tests\Feature\Financial;

use App\Enums\Shipping\ShippingCostBasis;
use App\Models\Coupon;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WeighbridgeReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the pure recalculation logic of WeighbridgeReconciler.
 * No wallet ops, no invoice generation — only POOM/PTSM derivation and billing-weight cap.
 */
class WeighbridgeReconcilerTest extends TestCase
{
    use DatabaseTransactions;

    private const SHIPPING = 500_000.0;

    private const UNIT_PRICE = 500.0;

    private const PROVINCE_ID = 8;

    private WeighbridgeReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->reconciler = app(WeighbridgeReconciler::class);
    }

    /**
     * ‏این دو تست قبلاً clamp را قفل می‌کردند («وزن مبنا = min(actual, WBS)»).
     * نسخه‌ی ۲ سند در بند ۸.۲ آن قاعده را برداشت، پس طبق قاعده‌ی مخزن
     * («تستِ رفتارِ عوض‌شده را تغییر بده، حذف نکن») ادعایشان وارونه شده است.
     */
    #[Test]
    public function billing_weight_is_the_actual_weight_on_over_delivery(): void
    {
        $order = $this->makeOrder(wbs: 10_000.0);

        $result = $this->reconciler->reconcile($order, actualWeight: 12_000.0);

        $this->assertSame(12_000.0, $result->billingWeight);
        $this->assertEqualsWithDelta(20.0, $result->overweightPercent, 0.001);
    }

    #[Test]
    public function over_delivery_raises_the_invoice_amount(): void
    {
        $order = $this->makeOrder(wbs: 10_000.0);

        $exact = $this->reconciler->reconcile($order, actualWeight: 10_000.0);
        $over = $this->reconciler->reconcile($order, actualWeight: 15_000.0);

        $this->assertGreaterThan($exact->newPOO, $over->newPOO,
            'clamp حذف شده است: اضافه‌بار واقعاً صورت‌حساب می‌شود');
        $this->assertGreaterThan(0.0, $over->diffPOO);
    }

    #[Test]
    public function billing_weight_equals_actual_on_under_delivery(): void
    {
        $order = $this->makeOrder(wbs: 10_000.0);

        $result = $this->reconciler->reconcile($order, actualWeight: 8_000.0);

        $this->assertSame(8_000.0, $result->billingWeight);
    }

    #[Test]
    public function diff_poo_is_negative_on_under_delivery(): void
    {
        // oldPOO = POO(wbs=10,000); reconcile with 8,000 → POOM < oldPOO → diffPOO < 0
        $order = $this->makeOrder(wbs: 10_000.0);
        $result = $this->reconciler->reconcile($order, actualWeight: 8_000.0);

        $this->assertLessThan(0.0, $result->diffPOO, 'Under-delivery must produce a negative diffPOO (buyer refund)');
        $this->assertLessThan(0.0, $result->diffPTS, 'Under-delivery must produce a negative diffPTS (supplier reclaim)');
    }

    #[Test]
    public function diff_poo_equals_zero_on_exact_weight(): void
    {
        // oldPOO is pre-computed from the same formula for wbs, so reconciling at wbs → diff=0
        $order = $this->makeOrder(wbs: 10_000.0);
        $result = $this->reconciler->reconcile($order, actualWeight: 10_000.0);

        $this->assertEqualsWithDelta(0.0, $result->diffPOO, 1.0, 'Exact weight must produce zero diffPOO');
    }

    #[Test]
    public function new_poo_matches_direct_calculator_call(): void
    {
        $calc = app(SettlementCalculatorService::class);
        $order = $this->makeOrder(wbs: 10_000.0);

        $result = $this->reconciler->reconcile($order, actualWeight: 8_000.0);

        $rates = $calc->getCommissionRates('pioneer');
        $expectedPOO = $calc->calculatePOO(
            self::SHIPPING, self::UNIT_PRICE, 8_000.0,
            $rates['ow_final_rate'], 10.0, true, true
        );
        $this->assertEqualsWithDelta($expectedPOO, $result->newPOO, 1.0);
    }

    #[Test]
    public function vat_from_order_is_passed_through_to_result(): void
    {
        $order = $this->makeOrder(wbs: 5_000.0, vatRate: 9.0);
        $result = $this->reconciler->reconcile($order, actualWeight: 5_000.0);

        $this->assertSame(9.0, $result->vat);
    }

    // ─── بازمحاسبه‌ی کرایه و کوپن (بند ۸.۲ گام‌های ۲ و ۴) ─────
    //
    // ⚠️ تا پیش از این، `makeOrder()` نه محموله می‌ساخت و نه کوپن، پس
    // `recalculateShipping()` همیشه روی `$shipments->isEmpty()` برمی‌گشت و
    // `recalculateCoupon()` همیشه روی `! $couponId`. یعنی هر دو شاخه‌ی واقعی در
    // هیچ تستی اجرا نمی‌شدند، و `newShippingCost`/`newCouponDiscount` هم در کلِ
    // مخزن assert نمی‌شدند — در حالی که `ProcessWeighbridgeResultAction`
    // هر دو را مستقیم روی سفارش می‌نویسد.

    /**
     * ‏قبلاً فیکسچر محموله‌ی بدونِ اسنپ‌شات می‌ساخت و بازمحاسبه از نرخِ **زنده**
     * را قفل می‌کرد — همان باگی که ویرایشِ نرخ را به شارژِ بی‌رضایت تبدیل می‌کرد.
     * حالا محموله مثل `PlaceOrderAction` اسنپ‌شات دارد و نرخِ زنده عمداً عوض می‌شود.
     */
    #[Test]
    public function freight_is_repriced_from_the_actual_shipment_weights(): void
    {
        $location = $this->makeFreightRoute(flat: 100_000.0, perKg: 50.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, 2_000.0, $this->snapshot(100_000, 50, placedWeight: 1_500));
        $this->editLiveRate($location, flat: 900_000, perKg: 900);

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 2_000.0);

        // 100_000 + 50 * 2_000 = 200_000 — نه کرایه‌ی اولیه‌ی 500_000، نه نرخِ زنده.
        $this->assertEqualsWithDelta(200_000.0, $result->newShippingCost, 0.01);
        $this->assertNotEquals(
            self::SHIPPING,
            $result->newShippingCost,
            'کرایه باید با وزنِ واقعی بازمحاسبه شود، نه اینکه مقدارِ اولیه برگردد.'
        );
    }

    #[Test]
    public function every_registered_leg_counts_towards_the_repriced_freight(): void
    {
        // ⚠️ نرخ عمداً طوری انتخاب شده که حاصل با `self::SHIPPING` **برابر نشود**.
        // نسخه‌ی اولِ این تست 100_000 + 50×8_000 = 500_000 می‌داد، که اتفاقاً
        // دقیقاً کرایه‌ی ذخیره‌شده بود — پس با غیرفعال‌کردنِ کلِ بازمحاسبه هم سبز
        // می‌ماند. تساویِ تصادفی همان چیزی است که این دور دنبالش بود.
        $location = $this->makeFreightRoute(flat: 80_000.0, perKg: 40.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, 5_000.0, $this->snapshot(80_000, 40, placedWeight: 5_000));
        $this->makeShipment($order, $location, 3_000.0, $this->snapshot(80_000, 40, placedWeight: 3_000));

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 8_000.0);

        // هر ردیفِ محموله یک لِگِ ثبت‌شده با اسنپ‌شاتِ خودش است (ثبتِ سفارش هرگز دو
        // ردیف با یک (مبدأ، مقصد) نمی‌سازد)، پس هر کدام flat خودش را دارد:
        // (80_000 + 40×5_000) + (80_000 + 40×3_000) = 480_000.
        $this->assertEqualsWithDelta(480_000.0, $result->newShippingCost, 0.01);
        $this->assertNotEquals(self::SHIPPING, $result->newShippingCost);
    }

    #[Test]
    public function a_count_billed_leg_is_repriced_from_its_snapshot_and_ignores_the_weight(): void
    {
        $location = $this->makeFreightRoute(flat: 100_000.0, perKg: 50.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, 2_500.0, $this->snapshot(70_000, 1_200, placedWeight: 2_000) + [
            Shipment::COST_BASIS => ShippingCostBasis::COUNT,
            Shipment::BILLED_COUNT => 40,
        ]);

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 2_500.0);

        // 70_000 + 1_200 × 40 — وزنِ واقعی مبنای تعدادی را جابه‌جا نمی‌کند.
        $this->assertEqualsWithDelta(118_000.0, $result->newShippingCost, 0.01);
    }

    /**
     * محموله‌ی پیش از اسنپ‌شات: کرایه‌ی **ثبت‌شده** به نسبتِ وزن مقیاس می‌شود و نرخِ
     * زنده (که 100_000 + 50×2_500 = 225_000 می‌داد) هرگز خوانده نمی‌شود.
     */
    #[Test]
    public function a_legacy_shipment_without_a_rate_snapshot_scales_its_placed_freight(): void
    {
        $location = $this->makeFreightRoute(flat: 100_000.0, perKg: 50.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, 2_500.0, [
            Shipment::WEIGHT => 2_000,
            Shipment::SHIPPING_COST => 300_000,
        ]);

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 2_500.0);

        $this->assertEqualsWithDelta(375_000.0, $result->newShippingCost, 0.01);
    }

    #[Test]
    public function a_legacy_count_billed_shipment_keeps_its_placed_freight(): void
    {
        $location = $this->makeFreightRoute(flat: 100_000.0, perKg: 50.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, 2_500.0, [
            Shipment::WEIGHT => 2_000,
            Shipment::BILLED_COUNT => 40,
            Shipment::COST_BASIS => ShippingCostBasis::COUNT,
            Shipment::SHIPPING_COST => 300_000,
        ]);

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 2_500.0);

        $this->assertEqualsWithDelta(300_000.0, $result->newShippingCost, 0.01);
    }

    #[Test]
    public function a_shipment_without_an_actual_weight_leaves_the_freight_untouched(): void
    {
        // مسیرِ باسکولِ ادمین: وزن کل مستقیم داده می‌شود و محموله‌ای
        // وزن ندارد. حدس‌زدنِ کرایه در این حالت بدتر از دست‌نزدن است.
        $location = $this->makeFreightRoute(flat: 100_000.0, perKg: 50.0);

        $order = $this->makeOrder(wbs: 10_000.0);
        $this->makeShipment($order, $location, null);

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 8_000.0);

        $this->assertEqualsWithDelta(self::SHIPPING, $result->newShippingCost, 0.01);
    }

    #[Test]
    public function a_percentage_coupon_is_rescaled_with_the_new_poo(): void
    {
        $order = $this->makeOrder(wbs: 10_000.0);
        $coupon = Coupon::create([
            Coupon::CODE => 'WR-'.Str::random(10),
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => 10,
            Coupon::IS_ACTIVE => true,
        ]);
        $order->forceFill([
            Order::COUPON_ID => $coupon->id,
            Order::COUPON_DISCOUNT_AMOUNT => 1_000.0,
        ])->save();

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 12_000.0);

        // ‏قبلاً «۱۰٪ از POOMِ تازه» را قفل می‌کرد. ولی تخفیف هنگامِ ثبت روی
        // `final_amount` محاسبه می‌شود نه POO، پس `۱۰٪ × POOM` حتی با وزنِ یکسان با
        // همین ۱_۰۰۰ فرق داشت — و آن اختلاف حالا واقعاً پول جابه‌جا می‌کند. تخفیفِ
        // پرداخت‌شده به نسبتِ POOM/POO مقیاس می‌شود.
        $oldPOO = (float) $order->payment_of_owner;
        $this->assertEqualsWithDelta(1_000.0 * $result->newPOO / $oldPOO, $result->newCouponDiscount, 1.0);
        $this->assertGreaterThan(1_000.0, $result->newCouponDiscount);
        $this->assertEqualsWithDelta($result->newCouponDiscount - 1_000.0, $result->couponDiscountDelta, 0.01);

        $exact = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 10_000.0);
        $this->assertEqualsWithDelta(0.0, $exact->couponDiscountDelta, 0.01,
            'با وزنِ یکسان هیچ پولی بابتِ کوپن نباید جابه‌جا شود.');
    }

    #[Test]
    public function a_fixed_amount_coupon_is_left_alone(): void
    {
        $order = $this->makeOrder(wbs: 10_000.0);
        $coupon = Coupon::create([
            Coupon::CODE => 'WR-'.Str::random(10),
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 250_000,
            Coupon::IS_ACTIVE => true,
        ]);
        $order->forceFill([
            Order::COUPON_ID => $coupon->id,
            Order::COUPON_DISCOUNT_AMOUNT => 250_000.0,
        ])->save();

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 12_000.0);

        $this->assertEqualsWithDelta(250_000.0, $result->newCouponDiscount, 0.01);
        $this->assertSame(0.0, $result->couponDiscountDelta);
    }

    // ─── پرچم‌های ±TC / ±VAT ──────────────────────────────────

    #[Test]
    public function the_reconciler_passes_the_orders_own_trans_flags_through(): void
    {
        // ⚠️ هر فیکسچری که به این‌جا می‌رسید هر دو پرچم را `true` می‌گذاشت،
        // پس کارِ اصلیِ reconciler — خواندنِ اسنپ‌شاتِ سفارش و پاس‌دادنِ
        // دو بولین در جای درست — قابلِ ابطال نبود.
        $order = $this->makeOrder(wbs: 10_000.0);
        $order->forceFill([
            Order::TRANS_COMMISSION => false,
            Order::TRANS_VAT => true,
        ])->save();

        $result = $this->reconciler->reconcile($order->fresh('items'), actualWeight: 10_000.0);

        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');

        $expected = $calc->calculatePOO(
            self::SHIPPING, self::UNIT_PRICE, 10_000.0,
            $rates['ow_final_rate'], 10.0,
            false,
            true
        );
        $bothTrue = $calc->calculatePOO(
            self::SHIPPING, self::UNIT_PRICE, 10_000.0,
            $rates['ow_final_rate'], 10.0, true, true
        );

        $this->assertEqualsWithDelta($expected, $result->newPOO, 0.01);
        // و assert بالا نباید با هر دو حالت سازگار باشد:
        $this->assertNotEqualsWithDelta($bothTrue, $result->newPOO, 0.01);
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates an Order whose payment_of_owner / payment_to_supplier are computed
     * from the same formula the reconciler uses, so exact-weight reconciliation
     * produces diffPOO = 0 and under-delivery produces diffPOO < 0.
     */
    private function makeOrder(float $wbs, float $vatRate = 10.0): Order
    {
        $buyer = User::factory()->create();
        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // Pre-compute consistent POO/PTS for the given wbs so tests are arithmetically correct
        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');
        $oldPOO = $calc->calculatePOO(self::SHIPPING, self::UNIT_PRICE, $wbs, $rates['ow_final_rate'], $vatRate, true, true);
        $oldPTS = $calc->calculatePTS(self::SHIPPING, self::UNIT_PRICE, $wbs, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vatRate, true, true);

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'TEST-WR-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => $oldPOO,
            Order::PAYMENT_STATUS => 'paid',
            Order::WEIGHT_BEFORE_SCALE => $wbs,
            Order::SHIPPING_COST => self::SHIPPING,
        ]);
        $order->forceFill([
            Order::OFFICIAL_PRICE => 0,
            Order::VAT_RATE => $vatRate,
            Order::PAYMENT_OF_OWNER => $oldPOO,
            Order::PAYMENT_TO_SUPPLIER => $oldPTS,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => self::UNIT_PRICE,
            OrderItem::TOTAL_PRICE => $wbs * self::UNIT_PRICE,
            OrderItem::QUANTITY => (int) $wbs,
        ]);

        $order->load('items');

        return $order;
    }

    /** یک مسیرِ کرایه‌ی واقعی (مبدأ + نرخ) تا `quote()` چیزی برای قیمت‌گذاری داشته باشد. */
    private function makeFreightRoute(float $flat, float $perKg): FactoryLocation
    {
        $factory = Factory::create([
            'name' => 'کارخانه تست',
            'slug' => 'wr-factory-'.Str::random(8),
        ]);

        $location = FactoryLocation::create([
            'factory_id' => $factory->id,
            'name' => 'انبار اصلی',
            'type' => 'main',
        ]);

        ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $location->id,
            ShippingRate::DESTINATION_PROVINCE_ID => self::PROVINCE_ID,
            ShippingRate::DESTINATION_CITY_ID => null,
            ShippingRate::FLAT_COST => $flat,
            ShippingRate::PER_KG_COST => $perKg,
            ShippingRate::IS_ACTIVE => true,
        ]);

        return $location;
    }

    /**
     * `actual_weight` عمداً `forceFill` است — ستون fillable نیست.
     *
     * @param  array<string, mixed>  $columns  ستون‌های ثبتِ سفارش (وزن، کرایه، اسنپ‌شاتِ نرخ)
     */
    private function makeShipment(Order $order, FactoryLocation $location, ?float $actualWeight, array $columns = []): Shipment
    {
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $order->id,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);

        $shipment->forceFill([
            Shipment::FACTORY_ID => $location->factory_id,
            Shipment::FACTORY_LOCATION_ID => $location->id,
            Shipment::DESTINATION_PROVINCE_ID => self::PROVINCE_ID,
            Shipment::ACTUAL_WEIGHT => $actualWeight,
        ] + $columns)->save();

        return $shipment;
    }

    /** @return array<string, mixed> همان ستون‌هایی که `PlaceOrderAction` برای یک لِگِ وزنی می‌نویسد. */
    private function snapshot(int $flat, int $perUnit, int $placedWeight): array
    {
        return [
            Shipment::WEIGHT => $placedWeight,
            Shipment::SHIPPING_COST => $flat + $perUnit * $placedWeight,
            Shipment::RATE_FLAT_COST => $flat,
            Shipment::RATE_UNIT_COST => $perUnit,
        ];
    }

    /** ویرایشِ نرخِ مسیر پس از ثبتِ سفارش — نباید روی باسکول اثر کند. */
    private function editLiveRate(FactoryLocation $location, int $flat, int $perKg): void
    {
        ShippingRate::where(ShippingRate::FACTORY_LOCATION_ID, $location->id)
            ->update([ShippingRate::FLAT_COST => $flat, ShippingRate::PER_KG_COST => $perKg]);
    }
}

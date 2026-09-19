<?php

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceSetting;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WeighbridgeReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قرارداد اسنپ‌شاتِ نرخ کمیسیون (%TC).
 *
 * باگی که این تست پین می‌کند: %TC از تنظیمِ سراسریِ `PriceSetting.tc_percentage`
 * زنده خوانده می‌شد — هم زمان پرداخت، هم زمان باسکول — در حالی که
 * `orders.commission_rate` عددِ هاردکدِ 1.0 را ذخیره می‌کرد و هرگز خوانده نمی‌شد.
 * پس ویرایشِ آن تنظیم بین پرداخت و باسکول، POOM را با وزنِ *کاملاً یکسان* از POO
 * دور می‌کرد و شاخه‌ی «کسری» را فعال می‌کرد: عودتِ واقعی به خریدار + ثبتِ بدهیِ
 * واقعی برای تأمین‌کننده، بدون آنکه یک کیلوگرم جابه‌جا شده باشد.
 */
class CommissionRateSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_reconciler_uses_the_orders_snapshot_not_the_live_setting(): void
    {
        $calculator = app(SettlementCalculatorService::class);
        $reconciler = app(WeighbridgeReconciler::class);

        // سفارشی با نرخِ اسنپ‌شات‌شده‌ی ۱٪ که POO/PTS آن با همان ۱٪ محاسبه شده.
        $order = $this->makeWeighableOrder($calculator, snapshotTc: 1.0);
        $wbs = (float) $order->{Order::WEIGHT_BEFORE_SCALE};

        // باسکول با وزنِ دقیقاً برابرِ وزنِ اولیه → اختلاف مالی باید صفر باشد.
        $result = $reconciler->reconcile($order, $wbs);

        $this->assertEqualsWithDelta(0.0, $result->diffPOO, 0.01,
            'با وزن یکسان و نرخ اسنپ‌شات‌شده، POOM باید برابر POO باشد.');
        $this->assertEqualsWithDelta(0.0, $result->diffPTS, 0.01,
            'با وزن یکسان و نرخ اسنپ‌شات‌شده، PTSM باید برابر PTS باشد.');
    }

    #[Test]
    public function a_changed_global_tc_rate_cannot_manufacture_a_deficit(): void
    {
        $calculator = app(SettlementCalculatorService::class);
        $reconciler = app(WeighbridgeReconciler::class);

        // سفارش با اسنپ‌شاتِ ۱٪ ساخته می‌شود …
        $order = $this->makeWeighableOrder($calculator, snapshotTc: 1.0);
        $wbs = (float) $order->{Order::WEIGHT_BEFORE_SCALE};

        // … و سپس نرخِ سراسری عوض می‌شود (سناریوی ویرایشِ تنظیمات پس از پرداخت).
        // اسنپ‌شات روی سفارش دست‌نخورده می‌ماند، پس نتیجه نباید تغییر کند.
        $order->forceFill([Order::COMMISSION_RATE => 1.0])->save();
        $withDifferentLiveRate = $reconciler->reconcile($order->fresh(['items', 'eliteYAR.badge']), $wbs);

        $this->assertEqualsWithDelta(0.0, $withDifferentLiveRate->diffPOO, 0.01);

        // و مهم‌تر: اگر همان سفارش با اسنپ‌شاتِ متفاوتی محاسبه شود، عدد عوض می‌شود —
        // یعنی reconciler واقعاً از ستون اسنپ‌شات می‌خواند، نه از مقدارِ ثابتِ کد.
        $order->forceFill([Order::COMMISSION_RATE => 2.0])->save();
        $shifted = $reconciler->reconcile($order->fresh(['items', 'eliteYAR.badge']), $wbs);

        $this->assertNotEqualsWithDelta($withDifferentLiveRate->newPOO, $shifted->newPOO, 0.01,
            'تغییرِ ستونِ اسنپ‌شات باید روی POOM اثر بگذارد — وگرنه نرخ از جای دیگری خوانده می‌شود.');
    }

    #[Test]
    public function editing_the_commission_shares_after_payment_cannot_move_money(): void
    {
        // از `a411c4f` سهم‌های OW/CO در پنل قابلِ ویرایش‌اند و باسکول آن‌ها را زنده
        // می‌خواند: ویرایشِ سهمِ OW بینِ پرداخت و باسکول، POOM را با وزنِ یکسان جابه‌جا
        // می‌کرد — عودت یا کسرِ واقعی بدونِ یک کیلوگرم تفاوت.
        $calculator = app(SettlementCalculatorService::class);
        $reconciler = app(WeighbridgeReconciler::class);
        $order = $this->makeWeighableOrder($calculator, snapshotTc: 1.0);
        $wbs = (float) $order->{Order::WEIGHT_BEFORE_SCALE};

        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '30']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_CO_SHARE_DEFAULT], [PriceSetting::NAME => 'co', PriceSetting::VALUE => '45']);

        $result = $reconciler->reconcile($order->fresh(['items', 'eliteYAR.badge']), $wbs);

        $this->assertEqualsWithDelta(0.0, $result->diffPOO, 0.01);
        $this->assertEqualsWithDelta(0.0, $result->diffPTS, 0.01);
    }

    #[Test]
    public function an_order_paid_before_the_share_snapshot_uses_the_catalog_defaults(): void
    {
        // سفارش‌های پیش از این تغییر با اعدادِ ثابتِ آن زمان پرداخت شده‌اند، نه با
        // مقدارِ زنده‌ی امروزِ پنل.
        $calculator = app(SettlementCalculatorService::class);
        $order = $this->makeWeighableOrder($calculator, snapshotTc: 1.0);
        $order->forceFill([Order::OW_SHARE_PERCENT => null, Order::CO_SHARE_PERCENT => null])->save();

        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '30']);

        $rates = $calculator->getCommissionRatesForOrder($order->fresh(), null);

        $this->assertSame(40.0, $rates['ow_percent_of_tc']);
        $this->assertSame(25.0, $rates['co_percent_of_tc']);
        $this->assertSame(35.0, $rates['ssg_percent_of_tc']);
    }

    #[Test]
    public function extract_vat_net_and_vat_sum_exactly_to_gross(): void
    {
        $calculator = app(SettlementCalculatorService::class);

        // مقادیری که با گردکردنِ مستقلِ net و vat، مجموعشان از gross فاصله می‌گرفت.
        foreach ([1_000.005, 1_100_000_000.0, 333_333.33, 7.77, 1_000_000_001.11] as $gross) {
            ['net' => $net, 'vat' => $vat] = $calculator->extractVat($gross, 10.0);

            $this->assertEqualsWithDelta(
                round($gross, 2),
                round($net + $vat, 2),
                0.0001,
                "net + vat باید دقیقاً برابر gross باشد (gross={$gross})."
            );
        }
    }

    /**
     * سفارشِ پرداخت‌شده‌ی آماده‌ی باسکول با POO/PTS محاسبه‌شده بر مبنای $snapshotTc.
     */
    private function makeWeighableOrder(SettlementCalculatorService $calculator, float $snapshotTc): Order
    {
        $order = Order::factory()->create([
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::SHIPPING_COST => 5_000_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => 250_000,
        ]);

        $rates = $calculator->getCommissionRatesForTc(null, $snapshotTc);
        $weight = 12_000.0;
        $trans = 5_000_000.0;
        $op = 250_000.0;
        $vat = 10.0;

        $poo = $calculator->calculatePOO($trans, $op, $weight, $rates['ow_final_rate'], $vat, true, true);
        $pts = $calculator->calculatePTS(
            $trans, $op, $weight,
            $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'],
            $vat, true, true
        );

        $order->forceFill([
            Order::WEIGHT_BEFORE_SCALE => $weight,
            Order::OFFICIAL_PRICE => $op,
            Order::VAT_RATE => $vat,
            Order::COMMISSION_RATE => $snapshotTc,
            Order::OWNER_DISCOUNT_RATE => $rates['ow_final_rate'],
            Order::SAZE_SHARE_RATE => $rates['ssg_final_rate'],
            Order::ELITE_YAR_SHARE_RATE => $rates['co_final_rate'],
            Order::OW_SHARE_PERCENT => $rates['ow_percent_of_tc'],
            Order::CO_SHARE_PERCENT => $rates['co_percent_of_tc'],
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
            Order::PAYMENT_OF_OWNER => $poo,
            Order::PAYMENT_TO_SUPPLIER => $pts,
        ])->save();

        return $order->fresh(['items', 'eliteYAR.badge']);
    }
}

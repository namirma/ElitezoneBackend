<?php

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\OrderItem;
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
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Enums\Shipping\ShippingCostBasis;
use App\Events\Financial\TransactionProcessed;
use App\Events\Order\OrderPaid;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قراردادِ اسنپ‌شاتِ نرخِ کرایه — هم‌الگوی `CommissionRateSnapshotTest`.
 *
 * باگی که این تست پین می‌کند: `WeighbridgeReconciler` کرایه را در باسکول از جدولِ
 * **زنده‌ی** `shipping_rates` دوباره قیمت می‌کرد. ادمین `flat_cost` یک مسیر را از
 * ۵۰۰ هزار به ۸۰۰ هزار می‌برد و سفارشی که **دقیقاً با وزنِ سفارش** توزین شده بود
 * `surplus` می‌شد و ۳۲۸٬۶۸۰ ریال از خریدار کسر می‌کرد — بی‌هیچ مرحله‌ی رضایتی، چون
 * گاردِ رضایتِ دوطرفه فقط وزن را نگاه می‌کند. کاهشِ نرخ برعکس: عودت + بدهیِ تأمین‌کننده.
 */
class FreightRateSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const WEIGHT = 10_000.0;

    private const PROVINCE_ID = 8;

    private const BUYER_FUNDS = 10_000_000;

    private User $buyer;

    private User $supplier;

    private FactoryLocation $location;

    private ShippingRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([OrderPaid::class, TransactionProcessed::class]);
        $this->mock(InvoiceGenerator::class)->shouldIgnoreMissing();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id, 'financial.vat_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 100_000_000, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => self::BUYER_FUNDS, Wallet::BALANCE_HOLD => 0]);
        $this->supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $factory = Factory::create(['name' => 'f', 'slug' => 'frs-'.Str::random(8)]);
        $this->location = FactoryLocation::create(['factory_id' => $factory->id, 'name' => 'l', 'type' => 'main']);
        $this->rate = ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $this->location->id,
            ShippingRate::DESTINATION_PROVINCE_ID => self::PROVINCE_ID,
            ShippingRate::DESTINATION_CITY_ID => null,
            ShippingRate::FLAT_COST => 500_000,
            ShippingRate::PER_KG_COST => 0,
            ShippingRate::IS_ACTIVE => true,
        ]);
    }

    /** ‏repro [6] از ممیزیِ پول — حالا رفتارِ درست را ادعا می‌کند. */
    #[Test]
    public function a_freight_rate_edit_cannot_charge_the_buyer_at_identical_weight(): void
    {
        $order = $this->weighableOrder(500_000, [Shipment::RATE_FLAT_COST => 500_000, Shipment::RATE_UNIT_COST => 0]);

        $this->rate->forceFill([ShippingRate::FLAT_COST => 800_000])->save(); // back-office edits the route price

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WEIGHT);

        $this->assertSame('equal', $result['status'], 'وزنِ یکسان یعنی هیچ اختلافِ مالی — نرخِ زنده نباید خوانده شود.');
        $this->assertSame(self::BUYER_FUNDS, (int) $this->walletOf($this->buyer)->balance);
        $this->assertEqualsWithDelta(500_000, (float) $order->fresh()->shipping_cost, 0.01);
    }

    #[Test]
    public function a_rate_cut_cannot_refund_the_buyer_or_indebt_the_supplier(): void
    {
        $order = $this->weighableOrder(500_000, [Shipment::RATE_FLAT_COST => 500_000, Shipment::RATE_UNIT_COST => 0]);

        $this->rate->forceFill([ShippingRate::FLAT_COST => 200_000])->save();

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WEIGHT);

        $this->assertSame('equal', $result['status']);
        $this->assertSame(self::BUYER_FUNDS, (int) $this->walletOf($this->buyer)->balance);
        $this->assertFalse((bool) $order->fresh()->{Order::SUPPLIER_DEBT_PENDING});
    }

    #[Test]
    public function a_rate_entered_after_a_zero_freight_placement_charges_nothing(): void
    {
        // سفارش وقتی ثبت شد که هنوز نرخی برای این مسیر وارد نشده بود → کرایه‌ی صفر.
        $this->rate->delete();
        $order = $this->weighableOrder(0, [Shipment::RATE_FLAT_COST => 0, Shipment::RATE_UNIT_COST => 0]);

        ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $this->location->id,
            ShippingRate::DESTINATION_PROVINCE_ID => self::PROVINCE_ID,
            ShippingRate::FLAT_COST => 800_000,
            ShippingRate::PER_KG_COST => 10,
            ShippingRate::IS_ACTIVE => true,
        ]);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WEIGHT);

        $this->assertSame('equal', $result['status']);
        $this->assertSame(self::BUYER_FUNDS, (int) $this->walletOf($this->buyer)->balance);
        $this->assertEqualsWithDelta(0, (float) $order->fresh()->shipping_cost, 0.01);
    }

    /** محموله‌ی پیش از مهاجرت: کرایه‌ی ثبت‌شده به نسبتِ وزن مقیاس می‌شود، پس وزنِ یکسان = صفر. */
    #[Test]
    public function a_legacy_shipment_without_a_snapshot_is_not_repriced_by_a_rate_edit(): void
    {
        $order = $this->weighableOrder(500_000, []);

        $this->rate->forceFill([ShippingRate::FLAT_COST => 800_000])->save();

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WEIGHT);

        $this->assertSame('equal', $result['status']);
        $this->assertSame(self::BUYER_FUNDS, (int) $this->walletOf($this->buyer)->balance);
    }

    /** منبعِ اسنپ‌شات: `quote()` همان مؤلفه‌هایی را برمی‌گرداند که لِگ را با آن قیمت کرد. */
    #[Test]
    public function the_placement_quote_hands_over_the_rate_components_it_priced_with(): void
    {
        $this->rate->forceFill([ShippingRate::PER_KG_COST => 40, ShippingRate::PER_COUNT_COST => 999])->save();
        ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $this->location->id,
            ShippingRate::DESTINATION_PROVINCE_ID => 9,
            ShippingRate::COST_BASIS => ShippingCostBasis::COUNT->value,
            ShippingRate::FLAT_COST => 70_000,
            ShippingRate::PER_KG_COST => 999,
            ShippingRate::PER_COUNT_COST => 1_200,
            ShippingRate::IS_ACTIVE => true,
        ]);

        $line = fn (int $addressId, int $province) => [
            'factory_id' => $this->location->factory_id,
            'factory_location_id' => $this->location->id,
            'address_id' => $addressId,
            'destination_province_id' => $province,
            'weight' => 2_000.0,
            'count' => 10.0,
        ];

        $groups = collect(app(ShippingCostCalculator::class)->quote([
            'w' => $line(1, self::PROVINCE_ID),
            'c' => $line(2, 9),
            'none' => $line(3, 31),
        ])->shipments)->keyBy(fn ($g) => $g->addressId);

        $this->assertSame([500_000.0, 40.0], [$groups[1]->rateFlatCost, $groups[1]->rateUnitCost]);
        $this->assertSame([70_000.0, 1_200.0], [$groups[2]->rateFlatCost, $groups[2]->rateUnitCost],
            'مبنای تعدادی باید per_count_cost را اسنپ‌شات کند، نه per_kg_cost.');
        $this->assertSame([0.0, 0.0], [$groups[3]->rateFlatCost, $groups[3]->rateUnitCost],
            'بدونِ نرخ، اسنپ‌شات صفر است تا نرخی که بعداً وارد شود کرایه نسازد.');
    }

    /**
     * سفارشِ پرداخت‌شده‌ی آماده‌ی باسکول با POO/PTS محاسبه‌شده روی `$trans`.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function weighableOrder(int $trans, array $snapshot): Order
    {
        $calc = app(SettlementCalculatorService::class);
        $tc = $calc->currentTcPercentage();
        $r = $calc->getCommissionRatesForTc('pioneer', $tc);
        $poo = $calc->calculatePOO($trans, 500, self::WEIGHT, $r['ow_final_rate'], 10, true, true);
        $pts = $calc->calculatePTS($trans, 500, self::WEIGHT, $r['ow_final_rate'], $r['ssg_final_rate'], $r['co_final_rate'], 10, true, true);

        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::PAYER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'FRS-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => $poo,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::WEIGHT_BEFORE_SCALE => self::WEIGHT,
            Order::SHIPPING_COST => $trans,
        ]);
        $order->forceFill([
            Order::OFFICIAL_PRICE => 500, Order::VAT_RATE => 10, Order::COMMISSION_RATE => $tc,
            Order::PAYMENT_OF_OWNER => $poo, Order::PAYMENT_TO_SUPPLIER => $pts,
            Order::TRANS_COMMISSION => true, Order::TRANS_VAT => true,
        ])->save();
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id, OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
            OrderItem::UNIT_PRICE => 500, OrderItem::TOTAL_PRICE => 5_000_000, OrderItem::QUANTITY => 10_000,
        ]);

        $shipment = Shipment::create([Shipment::ORDER_ID => $order->id, Shipment::STATUS => Shipment::STATUS_SHIPPED]);
        $shipment->forceFill([
            Shipment::FACTORY_ID => $this->location->factory_id,
            Shipment::FACTORY_LOCATION_ID => $this->location->id,
            Shipment::DESTINATION_PROVINCE_ID => self::PROVINCE_ID,
            Shipment::WEIGHT => self::WEIGHT,
            Shipment::COST_BASIS => ShippingCostBasis::WEIGHT,
            Shipment::SHIPPING_COST => $trans,
            Shipment::ACTUAL_WEIGHT => self::WEIGHT,
            Shipment::REGISTERED_AT => now(),
        ] + $snapshot)->save();

        return $order->fresh();
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::where(Wallet::USER_ID, $user->id)->firstOrFail();
    }
}

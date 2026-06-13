<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tests for ProcessWeighbridgeResultAction — the post-weighbridge settlement.
 *
 * Focus: the spec invariant "final invoice ≤ initial invoice". Over-delivery
 * (WAS > WBS) must NEVER charge the buyer (billing weight is capped at WBS);
 * under-delivery (WAS < WBS) refunds the buyer the POO − POOM difference.
 *
 * DatabaseTransactions: writes roll back at teardown; the live DB is not mutated.
 */
class ProcessWeighbridgeResultActionTest extends TestCase
{
    use DatabaseTransactions;

    private ProcessWeighbridgeResultAction $action;

    private User $buyer;

    private Wallet $buyerWallet;

    private Order $order;

    private float $oldPOO;

    protected function setUp(): void
    {
        parent::setUp();

        // PDF generation is irrelevant to the financial assertions — mock it so the
        // missing test-environment Blade views don't break the run.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateWeighbridgeDocs')
            ->andReturnNull();

        // SMS service has no TSMS credentials in test env — mock it so the constructor
        // does not throw TypeError (string property ← null config) and no real HTTP call
        // is made when the supplier-debt notification path is exercised.
        $this->mock(SmsService::class)
            ->shouldReceive('send')
            ->andReturn(true);

        $this->action = app(ProcessWeighbridgeResultAction::class);

        // System (Saze) + VAT escrow wallets — funded so deficit settlement can run.
        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        config(['financial.vat_user_id' => $systemUser->id]);
        Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0]);

        // Pioneer badge on the buyer so beneficiary.badge->code resolves without a null read.
        $badge = Badge::create([Badge::NAME => 'Pioneer', Badge::CODE => 'pioneer']);

        $this->buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        $this->buyerWallet = Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 1000, Wallet::BALANCE_HOLD => 0]);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0]);

        // Pre-weighbridge snapshot for WBS = 10, OP = 1000, no VAT, no shipping.
        // Computed via the same calculator so the test isn't coupled to the rate table.
        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');
        $this->oldPOO = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], 0, true, true);
        $oldPTS = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], 0, true, true);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id, // self → no consultant payout
            Order::ORDER_NUMBER => 'TEST-WB-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::OFFICIAL_PRICE => 1000,
            Order::WEIGHT_BEFORE_SCALE => 10,
            Order::SHIPPING_COST => 0,
            Order::VAT_RATE => 0,
            Order::PAYMENT_OF_OWNER => $this->oldPOO,
            Order::PAYMENT_TO_SUPPLIER => $oldPTS,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1000,
            OrderItem::TOTAL_PRICE => 10000,
            OrderItem::QUANTITY => 10,
        ]);
    }

    /** @test */
    public function over_delivery_never_charges_the_buyer(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (20) > WBS (10): billing weight is capped at WBS, so nothing extra is charged.
        $result = $this->action->execute($this->order, 20.0);

        $this->buyerWallet->refresh();
        $this->order->refresh();

        $this->assertSame('equal', $result['status'], 'Over-delivery must settle as equal, never surplus');
        $this->assertEquals($balanceBefore, (float) $this->buyerWallet->balance, 'Buyer must not be charged for over-delivery');
        $this->assertEqualsWithDelta($this->oldPOO, (float) $this->order->payment_of_owner, 1.0, 'Final POO must not exceed the initial POO');
        $this->assertEquals(20.0, (float) $this->order->weight_after_scale, 'Physical weight is still recorded');
    }

    /** @test */
    public function under_delivery_refunds_the_buyer(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (5) < WBS (10): deficit → buyer is refunded the POO − POOM difference.
        $result = $this->action->execute($this->order, 5.0);

        $this->buyerWallet->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertGreaterThan($balanceBefore, (float) $this->buyerWallet->balance, 'Buyer must be refunded on under-delivery');
    }
}

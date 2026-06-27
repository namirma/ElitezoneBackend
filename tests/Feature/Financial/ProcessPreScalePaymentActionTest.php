<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for ProcessPreScalePaymentAction — the direct-purchase (pre-scale) flow.
 *
 * DatabaseTransactions: every test wraps its DB writes in a transaction
 * that is rolled back at teardown. The live local database is NOT mutated.
 */
class ProcessPreScalePaymentActionTest extends TestCase
{
    use DatabaseTransactions;

    private ProcessPreScalePaymentAction $action;

    private User $buyer;

    private User $supplier;

    private Wallet $buyerWallet;

    private Wallet $supplierWallet;

    private Order $order;

    /** Below-threshold order amount (eligible for direct purchase) */
    private float $orderAmount;

    protected function setUp(): void
    {
        parent::setUp();

        // InvoiceGenerator performs file I/O (Blade view rendering + Storage write).
        // These tests verify financial side-effects only; PDF generation is mocked
        // so that missing test-environment Blade views don't break unrelated assertions.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateDirectPurchaseDocs')
            ->andReturnNull();

        $this->action = app(ProcessPreScalePaymentAction::class);
        $this->orderAmount = (float) config('financial.reservation_threshold', 1_950_000_000) - 1;

        // System (Saze) user — Action expects a user at config('financial.system_user_id').
        // The live DB might be empty, so create a fresh one and point config at it.
        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        Wallet::create([
            Wallet::USER_ID => $systemUser->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Buyer
        $this->buyer = User::factory()->create();
        $this->buyerWallet = Wallet::create([
            Wallet::USER_ID => $this->buyer->id,
            Wallet::BALANCE => $this->orderAmount * 2,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Supplier
        $this->supplier = User::factory()->create();
        $this->supplierWallet = Wallet::create([
            Wallet::USER_ID => $this->supplier->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Order + one OrderItem (needed to find supplier_user_id).
        // OFFICIAL_PRICE + WEIGHT_BEFORE_SCALE drive the POO/PTS calculation
        // inside the Action (see SettlementCalculatorService::calculatePOO).
        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'TEST-PRE-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_PENDING,
            Order::FINAL_AMOUNT => $this->orderAmount,
            Order::PAYMENT_STATUS => 'unpaid',
            Order::OFFICIAL_PRICE => $this->orderAmount,
            Order::WEIGHT_BEFORE_SCALE => 1,
            Order::SHIPPING_COST => 0,
            Order::VAT_RATE => 0,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
            OrderItem::UNIT_PRICE => $this->orderAmount,
            OrderItem::TOTAL_PRICE => $this->orderAmount,
            OrderItem::QUANTITY => 1,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_moves_order_to_processing_and_marks_it_paid(): void
    {
        $this->action->execute($this->order);

        $this->order->refresh();

        $this->assertEquals(Order::STATUS_PROCESSING, $this->order->status,
            'Order must transition to processing after direct purchase');

        $this->assertEquals('paid', $this->order->payment_status,
            'Payment status must be paid');

        $this->assertNotNull($this->order->paid_at,
            'paid_at timestamp must be set');
    }

    #[Test]
    public function it_debits_buyer_wallet(): void
    {
        // We assert "balance decreased" rather than an exact amount —
        // the precise debit follows the POO formula (rates × badge × VAT),
        // not raw final_amount. Coupling the test to that formula creates
        // brittle assertions.
        $balanceBefore = (float) $this->buyerWallet->balance;

        $this->action->execute($this->order);

        $this->buyerWallet->refresh();

        $this->assertLessThan(
            $balanceBefore,
            (float) $this->buyerWallet->balance,
            'Buyer wallet must be debited by the Action'
        );
    }

    #[Test]
    public function it_credits_the_system_saze_wallet(): void
    {
        // setUp() already created the system wallet — fetch it here
        $sazeWallet = Wallet::where(Wallet::USER_ID, (int) config('financial.system_user_id'))->firstOrFail();
        $sazeBefore = $sazeWallet->balance;

        $this->action->execute($this->order);

        $sazeWallet->refresh();
        $this->assertGreaterThan($sazeBefore, $sazeWallet->balance,
            'Saze (system) wallet must receive funds from buyer');
    }

    // ─────────────────────────────────────────────────────────────
    // Guard: insufficient balance
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_throws_insufficient_balance_and_leaves_order_unchanged(): void
    {
        // Drain the wallet to clearly below any plausible POO amount.
        // The POO calculation depends on rates × badge, so we drop to 1 rial
        // to guarantee an insufficient-balance exception regardless of formula.
        $this->buyerWallet->update([Wallet::BALANCE => 1]);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->action->execute($this->order);
        } finally {
            // Order must remain in pending status because the transaction rolled back
            $this->order->refresh();
            $this->assertEquals(Order::STATUS_PENDING, $this->order->status);
        }
    }
}

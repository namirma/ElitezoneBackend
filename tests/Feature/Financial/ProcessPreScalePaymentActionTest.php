<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->action      = app(ProcessPreScalePaymentAction::class);
        $this->orderAmount = (float) config('financial.reservation_threshold', 1_950_000_000) - 1;

        // Buyer
        $this->buyer = User::create([
            'mobile'   => '09' . rand(100000000, 999999999),
            'password' => bcrypt('secret'),
        ]);
        $this->buyerWallet = Wallet::create([
            Wallet::USER_ID      => $this->buyer->id,
            Wallet::BALANCE      => $this->orderAmount * 2,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Supplier
        $this->supplier = User::create([
            'mobile'   => '09' . rand(100000000, 999999999),
            'password' => bcrypt('secret'),
        ]);
        $this->supplierWallet = Wallet::create([
            Wallet::USER_ID      => $this->supplier->id,
            Wallet::BALANCE      => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Order + one OrderItem (needed to find supplier_user_id)
        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER   => 'TEST-PRE-' . now()->format('YmdHis') . rand(100, 999),
            Order::STATUS         => Order::STATUS_PENDING,
            Order::FINAL_AMOUNT   => $this->orderAmount,
            Order::PAYMENT_STATUS => 'unpaid',
        ]);

        OrderItem::create([
            'order_id'         => $this->order->id,
            'supplier_user_id' => $this->supplier->id,
            // other nullable fields will default
            'quantity'         => 1,
            'price'            => $this->orderAmount,
            'total'            => $this->orderAmount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────

    /** @test */
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

    /** @test */
    public function it_debits_buyer_wallet_by_the_full_order_amount(): void
    {
        $balanceBefore = $this->buyerWallet->balance;

        $this->action->execute($this->order);

        $this->buyerWallet->refresh();

        $this->assertEquals(
            $balanceBefore - $this->orderAmount,
            $this->buyerWallet->balance,
            'Buyer wallet must be debited by the exact order amount'
        );
    }

    /** @test */
    public function it_credits_the_system_saze_wallet(): void
    {
        $systemUserId = (int) config('financial.system_user_id', 1);
        $sazeWallet   = Wallet::firstOrCreate(
            [Wallet::USER_ID => $systemUserId],
            [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]
        );
        $sazeBefore = $sazeWallet->balance;

        $this->action->execute($this->order);

        $sazeWallet->refresh();
        $this->assertGreaterThan($sazeBefore, $sazeWallet->balance,
            'Saze (system) wallet must receive funds from buyer');
    }

    // ─────────────────────────────────────────────────────────────
    // Guard: insufficient balance
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_insufficient_balance_and_leaves_order_unchanged(): void
    {
        $this->buyerWallet->update([Wallet::BALANCE => $this->orderAmount - 1]);

        $this->expectException(InsufficientBalanceException::class);

        $this->action->execute($this->order);

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_PENDING, $this->order->status);
    }
}

<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\PayHoldFeeAction;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for PayHoldFeeAction — the hold-fee extension flow.
 *
 * DatabaseTransactions: every test wraps its DB writes in a transaction
 * that is rolled back at teardown. The live local database is NOT mutated.
 */
class PayHoldFeeActionTest extends TestCase
{
    use DatabaseTransactions;

    private PayHoldFeeAction $action;

    private User $buyer;

    private Wallet $wallet;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(PayHoldFeeAction::class);

        // System (Saze) user/wallet — PayHoldFeeAction transfers the fee here
        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        Wallet::create([
            Wallet::USER_ID => $systemUser->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Create minimal test user (factory sets type_id automatically)
        $this->buyer = User::factory()->create();

        // Fund the wallet above the hold fee
        $this->wallet = Wallet::create([
            Wallet::USER_ID => $this->buyer->id,
            Wallet::BALANCE => PayHoldFeeAction::fee() * 3,
            Wallet::BALANCE_HOLD => 0,
        ]);

        // Create a reserved order for this buyer
        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'TEST-'.Str::random(12),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::FINAL_AMOUNT => 5_000_000_000,
            Order::PAYMENT_STATUS => 'unpaid',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_extends_a_reserved_order_and_debits_the_fee(): void
    {
        $balanceBefore = $this->wallet->balance;

        $this->action->execute($this->order);

        $this->order->refresh();
        $this->wallet->refresh();

        $this->assertEquals(Order::STATUS_RESERVED_EXTENDED, $this->order->status);
        $this->assertNotNull($this->order->{Order::RESERVATION_EXPIRES_AT});
        $this->assertTrue($this->order->{Order::RESERVATION_EXPIRES_AT}->isFuture());

        $this->assertEquals(
            $balanceBefore - PayHoldFeeAction::fee(),
            $this->wallet->balance,
            'Wallet balance must decrease by exactly the hold fee'
        );
    }

    #[Test]
    public function it_records_a_wallet_transaction_for_the_fee_debit(): void
    {
        $this->action->execute($this->order);

        $tx = WalletTransaction::where('order_id', $this->order->id)
            ->where('wallet_id', $this->wallet->id)
            ->latest()
            ->first();

        $this->assertNotNull($tx, 'A WalletTransaction must be created for the debit');
        $this->assertEquals(PayHoldFeeAction::fee(), (float) $tx->amount);
    }

    // ─────────────────────────────────────────────────────────────
    // Idempotency guard
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_is_idempotent_when_order_is_already_extended_with_live_expiry(): void
    {
        // Pre-condition: order already extended with a future expiry
        $this->order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addHours(12),
        ]);

        $balanceBefore = $this->wallet->balance;
        $txCountBefore = WalletTransaction::where('order_id', $this->order->id)->count();

        // Executing again must be a no-op (no wallet debit, no new transaction).
        // The idempotency log line is verified implicitly — if the action weren't
        // returning early, it would throw (order not in RESERVED status) or debit.
        $this->action->execute($this->order);

        $this->wallet->refresh();
        $this->assertEquals($balanceBefore, $this->wallet->balance, 'No additional debit on duplicate call');
        $this->assertEquals(
            $txCountBefore,
            WalletTransaction::where('order_id', $this->order->id)->count(),
            'No additional WalletTransaction created'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Guard: wrong status
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_throws_when_order_is_not_reserved(): void
    {
        $this->order->update([Order::STATUS => Order::STATUS_PROCESSING]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تنها سفارش‌های رزرو شده قابل تمدید هستند');

        $this->action->execute($this->order);
    }

    // ─────────────────────────────────────────────────────────────
    // Guard: insufficient balance
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_throws_insufficient_balance_when_wallet_is_underfunded(): void
    {
        $this->wallet->update([Wallet::BALANCE => PayHoldFeeAction::fee() - 1]);

        $this->expectException(InsufficientBalanceException::class);

        $this->action->execute($this->order);
    }

    #[Test]
    public function it_throws_insufficient_balance_when_wallet_is_missing(): void
    {
        $this->wallet->delete();

        $this->expectException(InsufficientBalanceException::class);

        $this->action->execute($this->order);
    }

    // ─────────────────────────────────────────────────────────────
    // Config integration
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function hold_fee_is_read_from_config_not_hardcoded(): void
    {
        // Override config in this test only
        config(['financial.hold_fee' => 10_000_000]);

        $this->wallet->update([Wallet::BALANCE => 10_000_000]);

        $balanceBefore = $this->wallet->balance;

        $this->action->execute($this->order);

        $this->wallet->refresh();
        $this->assertEquals(0, $this->wallet->balance, 'Fee must match overridden config value');
        $this->assertEquals(
            $balanceBefore - 10_000_000,
            $this->wallet->balance
        );
    }
}

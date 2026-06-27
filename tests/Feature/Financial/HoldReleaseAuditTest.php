<?php

namespace Tests\Feature\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies WalletTransactionEngine::releaseHold() — the append-only audit trail
 * for escrow releases. A hold release must move balance_hold → balance AND leave
 * a distinct TYPE_HOLD_RELEASE ledger row (not silently mutate the original hold).
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class HoldReleaseAuditTest extends TestCase
{
    use DatabaseTransactions;

    private WalletTransactionEngine $engine;

    private User $buyer;

    private Wallet $wallet;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(WalletTransactionEngine::class);

        $this->buyer = User::factory()->create();

        // Wallet holding 100M Rials in escrow, 0 available.
        $this->wallet = Wallet::create([
            Wallet::USER_ID => $this->buyer->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 100_000_000,
        ]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'TEST-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::FINAL_AMOUNT => 100_000_000,
            Order::PAYMENT_STATUS => 'paid',
        ]);
    }

    #[Test]
    public function it_moves_hold_to_available_and_records_a_release_row(): void
    {
        $tx = $this->engine->releaseHold(
            (int) $this->buyer->id,
            40_000_000,
            $this->order,
            'release test',
        );

        $this->wallet->refresh();

        // Balance moved: hold 100M → 60M, available 0 → 40M
        $this->assertEquals(60_000_000, $this->wallet->balance_hold);
        $this->assertEquals(40_000_000, $this->wallet->balance);

        // Append-only audit row exists with the right type/status/amount
        $this->assertEquals(WalletTransaction::TYPE_HOLD_RELEASE, $tx->type);
        $this->assertEquals(FinancialCodes::STATUS_AVAILABLE, $tx->status);
        $this->assertEquals(40_000_000, (int) $tx->amount);
        $this->assertEquals($this->order->id, $tx->order_id);
        $this->assertEquals('hold_release', $tx->meta['op']);
    }

    #[Test]
    public function it_links_the_source_hold_in_meta(): void
    {
        // An original hold row, as ProcessWeighbridgeResultAction would have created.
        $hold = $this->wallet->transactions()->create([
            WalletTransaction::ORDER_ID => $this->order->id,
            WalletTransaction::AMOUNT => 100_000_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => FinancialCodes::STATUS_HOLD,
        ]);

        $release = $this->engine->releaseHold(
            (int) $this->buyer->id,
            100_000_000,
            $this->order,
            'full release',
            $hold,
        );

        $this->assertEquals($hold->id, $release->meta['source_hold_id']);

        // The hold and the release net to zero held — full audit reconstruction.
        $this->wallet->refresh();
        $this->assertEquals(0, $this->wallet->balance_hold);
        $this->assertEquals(100_000_000, $this->wallet->balance);
    }

    #[Test]
    public function it_rejects_releasing_more_than_is_held(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        $this->engine->releaseHold(
            (int) $this->buyer->id,
            150_000_000, // more than the 100M held
            $this->order,
        );
    }
}

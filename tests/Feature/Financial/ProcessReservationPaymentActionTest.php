<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tests for ProcessReservationPaymentAction — the reservation-start flow.
 *
 * DatabaseTransactions: every test wraps its DB writes in a transaction
 * that is rolled back at teardown. The live local database is NOT mutated.
 */
class ProcessReservationPaymentActionTest extends TestCase
{
    use DatabaseTransactions;

    private ProcessReservationPaymentAction $action;
    private User $buyer;
    private Wallet $wallet;
    private Order $order;

    /** Reservation threshold from config (1,950,000,000 Rials by default) */
    private float $threshold;

    protected function setUp(): void
    {
        parent::setUp();

        // InvoiceGenerator performs file I/O (Blade view rendering + Storage write).
        // These tests verify financial side-effects only; PDF generation is mocked
        // so that missing test-environment Blade views don't break unrelated assertions.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generate')
            ->andReturn('test/invoice-stub.pdf');

        $this->action    = app(ProcessReservationPaymentAction::class);
        $this->threshold = (float) config('financial.reservation_threshold', 1_950_000_000);

        // System (Saze) user/wallet — Action expects user at financial.system_user_id
        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        Wallet::create([
            Wallet::USER_ID      => $systemUser->id,
            Wallet::BALANCE      => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->buyer = User::factory()->create();

        // Wallet funded exactly at the threshold (just enough)
        $this->wallet = Wallet::create([
            Wallet::USER_ID      => $this->buyer->id,
            Wallet::BALANCE      => $this->threshold,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER   => 'TEST-RES-' . now()->format('YmdHis') . rand(100, 999),
            Order::STATUS         => Order::STATUS_PENDING,
            Order::FINAL_AMOUNT   => $this->threshold * 2, // above threshold
            Order::PAYMENT_STATUS => 'unpaid',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_sets_order_to_reserved_and_holds_the_reserve_amount(): void
    {
        $balanceBefore = $this->wallet->balance;

        $this->action->execute($this->order, $this->threshold, validityHours: 2);

        $this->order->refresh();
        $this->wallet->refresh();

        $this->assertEquals(Order::STATUS_RESERVED, $this->order->status,
            'Order status must be reserved after action');

        $this->assertNotNull($this->order->{Order::RESERVATION_EXPIRES_AT});
        $this->assertTrue($this->order->{Order::RESERVATION_EXPIRES_AT}->isFuture(),
            'Reservation expiry must be in the future');

        // The buyer's liquid balance should decrease by the reserve amount
        $this->assertEquals(
            $balanceBefore - $this->threshold,
            $this->wallet->balance,
            'Buyer balance must decrease by the reserve amount'
        );
    }

    /** @test */
    public function it_stores_payment_to_reserve_on_the_order(): void
    {
        $this->action->execute($this->order, $this->threshold, validityHours: 2);

        $this->order->refresh();
        $this->assertEquals(
            $this->threshold,
            (float) $this->order->payment_to_reserve,
            'payment_to_reserve must be recorded on the order'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Guard: insufficient balance
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_when_buyer_cannot_cover_the_reserve_amount(): void
    {
        $this->wallet->update([Wallet::BALANCE => $this->threshold - 1]);

        $this->expectException(InsufficientBalanceException::class);

        $this->action->execute($this->order, $this->threshold, validityHours: 2);
    }

    /** @test */
    public function order_remains_pending_when_balance_is_insufficient(): void
    {
        $this->wallet->update([Wallet::BALANCE => 0]);

        try {
            $this->action->execute($this->order, $this->threshold, validityHours: 2);
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_PENDING, $this->order->status,
            'Order must remain pending when reservation payment fails');
    }

    // ─────────────────────────────────────────────────────────────
    // Validity window
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_respects_the_validity_hours_parameter(): void
    {
        $this->action->execute($this->order, $this->threshold, validityHours: 48);

        $this->order->refresh();

        $expiresAt = $this->order->{Order::RESERVATION_EXPIRES_AT};
        $expectedExpiry = now()->addHours(48);

        $this->assertEqualsWithDelta(
            $expectedExpiry->timestamp,
            $expiresAt->timestamp,
            5,                // within 5 seconds
            'Expiry must be ~48 hours from now'
        );
    }
}

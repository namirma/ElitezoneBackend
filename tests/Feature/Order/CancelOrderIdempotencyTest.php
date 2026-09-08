<?php

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CancelOrderAction must be idempotent: a retried/concurrent cancel (admin double-click,
 * or the reservation-expiry cron racing a manual cancel — admin routes have no
 * idempotency middleware) must NOT restore stock or refund a second time.
 *
 * After the first cancel commits, the row is CANCELLED; the in-transaction lockForUpdate
 * + already-cancelled guard makes a second call a no-op. We prove it by asserting the
 * second call never touches the inventory service.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class CancelOrderIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function cancelling_an_already_cancelled_order_does_not_restore_stock_again(): void
    {
        $buyer = User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'CANCEL-'.Str::random(12),
            Order::STATUS => Order::STATUS_CANCELLED,        // already cancelled
            Order::PAYMENT_STATUS => Order::PAYMENT_FAILED,  // not paid → no invoice path
            Order::FINAL_AMOUNT => 0,
        ]);

        // A second cancel must never re-run stock restoration.
        $this->mock(InventoryService::class, function ($mock) {
            $mock->shouldReceive('restoreStock')->never();
        });

        $result = app(CancelOrderAction::class)->execute($order, 'retry', refundWallet: true, isSystemCron: true);

        $this->assertSame(Order::STATUS_CANCELLED, $result->status);
    }
}

<?php

namespace Tests\Feature\Audit;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Verifies the newly-added model factories produce valid, persistable models
 * (audit gap: only User/OrderItem had factories, making setup brittle).
 */
#[Group('audit-fixes')]
class FactoriesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_factory_creates_valid_order(): void
    {
        $order = Order::factory()->create();
        $this->assertNotNull($order->id);
        $this->assertNotNull($order->placer_user_id);
        $this->assertNotEmpty($order->order_number);

        $paid = Order::factory()->paid()->create();
        $this->assertSame(Order::PAYMENT_PAID, $paid->payment_status);
    }

    public function test_wallet_factory_creates_valid_wallet(): void
    {
        $wallet = Wallet::factory()->withBalance(1_000_000, 200_000)->create();
        $this->assertNotNull($wallet->user_id);
        $this->assertEqualsWithDelta(1_000_000, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(200_000, (float) $wallet->balance_hold, 0.01);
    }

    public function test_wallet_transaction_factory_creates_valid_transaction(): void
    {
        $tx = WalletTransaction::factory()->pending()->create();
        $this->assertNotNull($tx->wallet_id);
        $this->assertSame(WalletTransaction::STATUS_PENDING, $tx->status);
        $this->assertGreaterThan(0, (float) $tx->amount);
    }

    public function test_coupon_factory_creates_valid_coupon(): void
    {
        $coupon = Coupon::factory()->create();
        $this->assertNotEmpty($coupon->code);
        $this->assertSame(Coupon::TYPE_PERCENT, $coupon->type);

        $fixed = Coupon::factory()->fixed()->create();
        $this->assertSame(Coupon::TYPE_FIXED, $fixed->type);
    }
}

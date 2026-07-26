<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Supplier order REJECT — the missing half of "تأیید/رد سفارش" (accept = markAsPrepared).
 * Here we pin the controller's own guards (authorization + status window); the full
 * buyer-refund money flow is CancelOrderAction's tested responsibility.
 */
class SupplierOrderRejectTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:Order,1:User} [order, supplier] */
    private function makeSupplierOrder(string $status = Order::STATUS_PROCESSING): array
    {
        $buyer = User::factory()->create();
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'REJ-'.Str::random(12),
            Order::STATUS => $status,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return [$order, $supplier];
    }

    #[Test]
    public function a_non_supplier_cannot_reject_the_order(): void
    {
        [$order] = $this->makeSupplierOrder();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/profile/orders/{$order->id}/reject", ['reason' => 'تست'])->assertForbidden();
    }

    #[Test]
    public function the_supplier_cannot_reject_once_past_processing(): void
    {
        [$order, $supplier] = $this->makeSupplierOrder(Order::STATUS_PREPARING);
        Sanctum::actingAs($supplier);

        $this->postJson("/api/profile/orders/{$order->id}/reject", ['reason' => 'موجودی ندارم'])
            ->assertStatus(400);
    }

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        [$order, $supplier] = $this->makeSupplierOrder();
        Sanctum::actingAs($supplier);

        $this->postJson("/api/profile/orders/{$order->id}/reject", [])->assertStatus(422);
    }
}

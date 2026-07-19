<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-service supplier order queue + dashboard (GET /profile/supplier/orders, /dashboard).
 * Pins the scoping guarantee: a supplier must only ever see orders carrying their own
 * order_items.supplier_user_id — never another supplier's (the whole point of the endpoint).
 */
class SupplierOrderQueueTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSupplier(): User
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $supplier;
    }

    private function makeOrderForSupplier(User $supplier, string $status = Order::STATUS_PROCESSING): Order
    {
        $buyer = User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'QUE-'.now()->format('YmdHis').rand(1000, 9999),
            Order::STATUS => $status,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return $order;
    }

    #[Test]
    public function a_non_supplier_cannot_see_the_order_queue(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/orders')->assertStatus(403);
    }

    #[Test]
    public function a_non_supplier_cannot_see_the_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/dashboard')->assertStatus(403);
    }

    #[Test]
    public function a_supplier_only_sees_their_own_orders_in_the_queue(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $myOrder = $this->makeOrderForSupplier($supplierA);
        $otherOrder = $this->makeOrderForSupplier($supplierB);

        Sanctum::actingAs($supplierA);

        $ids = collect($this->getJson('/api/profile/supplier/orders')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($myOrder->id));
        $this->assertFalse($ids->contains($otherOrder->id));
    }

    #[Test]
    public function the_order_queue_can_be_filtered_by_status(): void
    {
        $supplier = $this->makeSupplier();
        $processing = $this->makeOrderForSupplier($supplier, Order::STATUS_PROCESSING);
        $preparing = $this->makeOrderForSupplier($supplier, Order::STATUS_PREPARING);

        Sanctum::actingAs($supplier);

        $ids = collect(
            $this->getJson('/api/profile/supplier/orders?status='.Order::STATUS_PROCESSING)
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($processing->id));
        $this->assertFalse($ids->contains($preparing->id));
    }

    #[Test]
    public function the_dashboard_reports_order_counts_by_status_and_wallet_summary(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrderForSupplier($supplier, Order::STATUS_PROCESSING);
        $this->makeOrderForSupplier($supplier, Order::STATUS_PROCESSING);
        $this->makeOrderForSupplier($supplier, Order::STATUS_PREPARING);

        Sanctum::actingAs($supplier);

        $response = $this->getJson('/api/profile/supplier/dashboard')->assertOk();

        $stats = collect($response->json('data.order_stats'))->keyBy('status');

        $this->assertSame(2, $stats[Order::STATUS_PROCESSING]['count']);
        $this->assertSame(1, $stats[Order::STATUS_PREPARING]['count']);
        $this->assertArrayHasKey('wallet', $response->json('data'));
        $this->assertArrayHasKey('pending_debt', $response->json('data'));
    }

    #[Test]
    public function the_dashboard_excludes_another_suppliers_orders_from_the_stats(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $this->makeOrderForSupplier($supplierA, Order::STATUS_PROCESSING);
        $this->makeOrderForSupplier($supplierB, Order::STATUS_PROCESSING);
        $this->makeOrderForSupplier($supplierB, Order::STATUS_PROCESSING);

        Sanctum::actingAs($supplierA);

        $stats = collect(
            $this->getJson('/api/profile/supplier/dashboard')->assertOk()->json('data.order_stats')
        )->keyBy('status');

        $this->assertSame(1, $stats[Order::STATUS_PROCESSING]['count']);
    }

    // ── Pending debts / pending Moadian drill-downs ────────────────────

    #[Test]
    public function a_non_supplier_cannot_see_pending_debts_or_pending_moadian(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/orders/pending-debts')->assertStatus(403);
        $this->getJson('/api/profile/supplier/orders/pending-moadian')->assertStatus(403);
    }

    #[Test]
    public function pending_debts_only_lists_my_orders_with_debt_pending(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $myDebt = $this->makeOrderForSupplier($supplierA, Order::STATUS_WEIGHBRIDGE_DONE);
        $myDebt->forceFill([Order::SUPPLIER_DEBT_PENDING => true, Order::SUPPLIER_DEBT_AMOUNT => 500_000])->save();

        // Mine, but no debt — must not appear.
        $this->makeOrderForSupplier($supplierA, Order::STATUS_WEIGHBRIDGE_DONE);

        // Another supplier's debt — must not appear.
        $theirDebt = $this->makeOrderForSupplier($supplierB, Order::STATUS_WEIGHBRIDGE_DONE);
        $theirDebt->forceFill([Order::SUPPLIER_DEBT_PENDING => true, Order::SUPPLIER_DEBT_AMOUNT => 500_000])->save();

        Sanctum::actingAs($supplierA);

        $ids = collect($this->getJson('/api/profile/supplier/orders/pending-debts')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertSame([$myDebt->id], $ids->all());
    }

    #[Test]
    public function pending_moadian_only_lists_my_weighed_orders_awaiting_doc_o(): void
    {
        $supplier = $this->makeSupplier();

        $awaiting = $this->makeOrderForSupplier($supplier, Order::STATUS_WEIGHBRIDGE_DONE);
        $awaiting->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();

        // Already submitted — must not appear.
        $done = $this->makeOrderForSupplier($supplier, Order::STATUS_WEIGHBRIDGE_DONE);
        $done->forceFill([Order::WEIGHING_COMPLETED_AT => now(), Order::SUPPLIER_MOADIAN_REF => 'REF-123'])->save();

        // Not weighed yet — must not appear.
        $this->makeOrderForSupplier($supplier, Order::STATUS_PROCESSING);

        Sanctum::actingAs($supplier);

        $ids = collect($this->getJson('/api/profile/supplier/orders/pending-moadian')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertSame([$awaiting->id], $ids->all());
    }
}

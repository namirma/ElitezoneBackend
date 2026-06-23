<?php

namespace Tests\Feature\Policies;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Tests\TestCase;

/**
 * Tests for OrderPolicy.
 *
 * DatabaseTransactions: every test wraps its DB writes in a transaction
 * that is rolled back at teardown. The live local database is NOT mutated.
 */
class OrderPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private User $beneficiary;

    private User $stranger;

    private User $supplier;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->beneficiary = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->supplier = User::factory()->create();
        $this->admin = User::factory()->create();

        // Roles (idempotent via firstOrCreate so we don't conflict with seed data)
        $supplierRole = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);

        $this->supplier->addRole($supplierRole);
        $this->admin->addRole($adminRole);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->owner->id,
            Order::BENEFICIARY_USER_ID => $this->beneficiary->id,
            Order::ORDER_NUMBER => 'POLICY-TEST-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // before() — admin bypass
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_view_any_order(): void
    {
        $this->assertTrue($this->admin->can('view', $this->order));
    }

    /** @test */
    public function admin_can_cancel_any_order(): void
    {
        $this->assertTrue($this->admin->can('cancel', $this->order));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // view
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_view_their_order(): void
    {
        $this->assertTrue($this->owner->can('view', $this->order));
    }

    /** @test */
    public function beneficiary_can_view_their_order(): void
    {
        $this->assertTrue($this->beneficiary->can('view', $this->order));
    }

    /** @test */
    public function supplier_can_view_orders_with_their_items(): void
    {
        $this->assertTrue($this->supplier->can('view', $this->order));
    }

    /** @test */
    public function stranger_cannot_view_anothers_order(): void
    {
        $this->assertFalse($this->stranger->can('view', $this->order));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // pay
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_pay_their_order(): void
    {
        $this->assertTrue($this->owner->can('pay', $this->order));
    }

    /** @test */
    public function beneficiary_can_pay_the_order(): void
    {
        $this->assertTrue($this->beneficiary->can('pay', $this->order));
    }

    /** @test */
    public function stranger_cannot_pay_anothers_order(): void
    {
        $this->assertFalse($this->stranger->can('pay', $this->order));
    }

    /** @test */
    public function nobody_can_pay_an_already_paid_order(): void
    {
        $this->order->update([Order::PAYMENT_STATUS => Order::PAYMENT_PAID]);

        $this->assertFalse($this->owner->can('pay', $this->order));
        // admin bypass still applies — before() short-circuits
        $this->assertTrue($this->admin->can('pay', $this->order));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // cancel
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_cancel_pending_order(): void
    {
        $this->assertTrue($this->owner->can('cancel', $this->order));
    }

    /** @test */
    public function nobody_can_cancel_a_shipped_order(): void
    {
        $this->order->update([Order::STATUS => Order::STATUS_SHIPPED]);

        $this->assertFalse($this->owner->can('cancel', $this->order));
        // admin override still works
        $this->assertTrue($this->admin->can('cancel', $this->order));
    }

    /** @test */
    public function nobody_can_cancel_a_completed_order(): void
    {
        $this->order->update([Order::STATUS => Order::STATUS_COMPLETED]);

        $this->assertFalse($this->owner->can('cancel', $this->order));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // manageAsSupplier
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function supplier_with_items_can_manage_order(): void
    {
        $this->assertTrue($this->supplier->can('manageAsSupplier', $this->order));
    }

    /** @test */
    public function non_supplier_cannot_manage_as_supplier(): void
    {
        $this->assertFalse($this->owner->can('manageAsSupplier', $this->order));
        $this->assertFalse($this->stranger->can('manageAsSupplier', $this->order));
    }

    /** @test */
    public function supplier_without_items_in_order_cannot_manage(): void
    {
        $otherSupplier = User::factory()->create();
        $supplierRole = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);
        $otherSupplier->addRole($supplierRole);

        $this->assertFalse($otherSupplier->can('manageAsSupplier', $this->order));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // finalizeInvoice
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_finalize_invoice(): void
    {
        $this->assertTrue($this->owner->can('finalizeInvoice', $this->order));
    }

    /** @test */
    public function beneficiary_can_finalize_invoice(): void
    {
        $this->assertTrue($this->beneficiary->can('finalizeInvoice', $this->order));
    }

    /** @test */
    public function stranger_cannot_finalize_invoice(): void
    {
        $this->assertFalse($this->stranger->can('finalizeInvoice', $this->order));
    }
}

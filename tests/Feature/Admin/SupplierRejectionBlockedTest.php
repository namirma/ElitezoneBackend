<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * رد کردن تأمین‌کننده تا تسویه‌ی آخرین سفارش ممنوع است (تصمیم مدیریت).
 *
 * ⚠️ **چرا.** `reject` نقشِ `supplier` را برمی‌دارد، و
 * `OrderPolicy::manageAsSupplier` پیش از هر چیز `hasRole('supplier')` را
 * می‌سنجد. پس تأمین‌کننده‌ای که وسطِ کار رد شود روی سفارشِ **پرداخت‌شده‌ی**
 * خودش نه بارنامه می‌تواند ثبت کند، نه فاکتور مودیان (سند O) بدهد، نه خسارت
 * عودت — سفارش قفل می‌شود و هولد مالیاتش برای همیشه در `balance_hold` می‌ماند.
 *
 * دو حالتِ آخر (بدهی معلق، و سند O نیامده روی سفارشِ تحویل‌شده) از گاردِ
 * وضعیت‌محورِ قبلی **رد می‌شدند** — سفارش `delivered` بود ولی پولش معلق.
 */
class SupplierRejectionBlockedTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(\App\Models\Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);

        $this->supplier = User::factory()->create();

        // ‏`status` عمداً در `$fillable` نیست (خودتأییدی ممنوع)، پس
        // `updateOrCreate` بی‌صدا دورش می‌ریخت و پروفایل `pending` می‌ماند.
        $profile = SupplierProfile::firstOrCreate([SupplierProfile::USER_ID => $this->supplier->id]);
        $profile->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED])->save();

        // نقش را واقعاً وصل می‌کنیم — همان کاری که `AdminSupplierController::approve`
        // می‌کند. بدون آن، «رد شد» و «هرگز نداشت» از هم قابل تفکیک نیستند و تست
        // چیزی را که ادعا می‌کند نمی‌سنجد.
        $this->supplier->addRole(
            \App\Models\Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier'])
        );
    }

    /** سفارشی با یک قلمِ متعلق به این تأمین‌کننده. */
    private function orderWith(array $attributes): Order
    {
        $buyer = User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'EZ-'.uniqid(),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $order->forceFill($attributes)->save();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);

        return $order->refresh();
    }

    private function reject(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        // ردِ بی‌دلیل دیگر مجاز نیست: متقاضی باید بداند چه چیزی را اصلاح
        // کند، مخصوصاً حالا که ویرایشِ پروفایل او را به صف برمی‌گرداند.
        return $this->postJson("/api/admin/suppliers/{$this->supplier->id}/reject", [
            'reason' => 'مدارک ارائه‌شده برای تأیید کافی نیست.',
        ]);
    }

    #[Test]
    public function an_order_still_in_flight_blocks_the_rejection(): void
    {
        $this->orderWith([Order::STATUS => Order::STATUS_PROCESSING]);

        $this->reject()->assertStatus(422);

        $this->assertTrue($this->supplier->fresh()->hasRole('supplier'));
        $this->assertSame(
            SupplierProfile::STATUS_APPROVED,
            SupplierProfile::where(SupplierProfile::USER_ID, $this->supplier->id)->value(SupplierProfile::STATUS)
        );
    }

    /** گاردِ وضعیت‌محورِ قبلی این را رد می‌کرد — سفارش تحویل شده ولی بدهی مانده. */
    #[Test]
    public function a_delivered_order_with_a_pending_supplier_debt_still_blocks(): void
    {
        $this->orderWith([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => 5_000_000,
        ]);

        $this->reject()->assertStatus(422);
        $this->assertTrue($this->supplier->fresh()->hasRole('supplier'));
    }

    /** و این یکی هم — باسکول خورده ولی سند O نیامده، پس مالیات هنوز هولد است. */
    #[Test]
    public function a_delivered_order_whose_vat_hold_is_unreleased_still_blocks(): void
    {
        $this->orderWith([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::SUPPLIER_HOLD_RELEASED_AT => null,
        ]);

        $this->reject()->assertStatus(422);
        $this->assertTrue($this->supplier->fresh()->hasRole('supplier'));
    }

    #[Test]
    public function a_fully_settled_order_does_not_block_the_rejection(): void
    {
        $this->orderWith([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::SUPPLIER_HOLD_RELEASED_AT => now(),
            Order::SUPPLIER_DEBT_PENDING => false,
        ]);

        $this->reject()->assertOk();

        $this->assertFalse($this->supplier->fresh()->hasRole('supplier'));
        $this->assertSame(
            SupplierProfile::STATUS_REJECTED,
            SupplierProfile::where(SupplierProfile::USER_ID, $this->supplier->id)->value(SupplierProfile::STATUS)
        );
    }

    #[Test]
    public function a_cancelled_order_does_not_block_the_rejection(): void
    {
        $this->orderWith([Order::STATUS => Order::STATUS_CANCELLED]);

        $this->reject()->assertOk();
        $this->assertFalse($this->supplier->fresh()->hasRole('supplier'));
    }

    #[Test]
    public function a_supplier_with_no_orders_can_be_rejected(): void
    {
        $this->reject()->assertOk();
        $this->assertFalse($this->supplier->fresh()->hasRole('supplier'));
    }

    /** سفارشِ تأمین‌کننده‌ی دیگر نباید این یکی را ببندد. */
    #[Test]
    public function another_suppliers_open_order_is_irrelevant(): void
    {
        $other = User::factory()->create();
        $buyer = User::factory()->create();

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'EZ-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'supplier_user_id' => $other->id,
        ]);

        $this->reject()->assertOk();
    }
}

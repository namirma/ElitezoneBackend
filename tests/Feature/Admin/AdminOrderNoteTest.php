<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پاسخِ مدیریت به پرسشِ ۱۴ (۱۴۰۵/۰۷/۱۳، گزینه‌ی الف): بک‌آفیس یک متنِ آزاد روی سفارش
 * می‌نویسد («توضیح شرایط خاص») که خریدار و تأمین‌کننده در پروفایل می‌بینند؛ اگر نوشته
 * نشده، چیزی نمایش داده نمی‌شود (کلیدِ غایب — قاعده‌ی صداقت).
 */
class AdminOrderNoteTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    private User $buyer;

    private User $supplier;

    private User $eliteYAR;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);

        $this->buyer = User::factory()->create();
        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        $this->eliteYAR = User::factory()->create([User::IS_SAZE90_USER => true]);
        $this->eliteYAR->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'EliteYAR']));

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::PAYER_USER_ID => $this->buyer->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::ORDER_NUMBER => 'NOTE-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    #[Test]
    public function the_note_is_absent_until_the_admin_writes_one_then_the_buyer_and_supplier_see_it(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/profile/orders/{$this->order->id}")->assertOk()
            ->assertJsonMissingPath('data.admin_note');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/orders/{$this->order->id}/note", ['admin_note' => 'تحویل به‌علت تعطیلی کارخانه دو روز عقب می‌افتد.'])
            ->assertOk()
            ->assertJsonPath('data.admin_note', 'تحویل به‌علت تعطیلی کارخانه دو روز عقب می‌افتد.');

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/profile/orders/{$this->order->id}")->assertOk()
            ->assertJsonPath('data.admin_note', 'تحویل به‌علت تعطیلی کارخانه دو روز عقب می‌افتد.');

        Sanctum::actingAs($this->supplier);
        $this->getJson('/api/profile/supplier/orders')->assertOk()
            ->assertJsonPath('data.0.admin_note', 'تحویل به‌علت تعطیلی کارخانه دو روز عقب می‌افتد.');

        // الیت‌یار طرفِ تحویل نیست — توضیحِ شرایطِ تحویل برای او نیست.
        Sanctum::actingAs($this->eliteYAR);
        $this->getJson("/api/profile/orders/{$this->order->id}")->assertOk()
            ->assertJsonMissingPath('data.admin_note');
    }

    #[Test]
    public function clearing_the_note_hides_it_again_and_leaves_an_audit_trail(): void
    {
        $this->order->forceFill([Order::ADMIN_NOTE => 'قدیمی'])->save();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/orders/{$this->order->id}/note", ['admin_note' => '   '])->assertOk()
            ->assertJsonMissingPath('data.admin_note');

        $this->assertNull($this->order->fresh()->getAttribute(Order::ADMIN_NOTE));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Order::class,
            'subject_id' => $this->order->id,
            'description' => 'توضیح بک‌آفیس از روی سفارش برداشته شد.',
        ]);

        $this->putJson("/api/admin/orders/{$this->order->id}/note", ['admin_note' => str_repeat('x', 2001)])
            ->assertStatus(422);
    }
}

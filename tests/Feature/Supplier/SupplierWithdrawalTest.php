<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * انصراف از تأمین‌کنندگی باید پروفایل **و** نقش را با هم ببرد.
 *
 * `DELETE /profile/supplier` فقط ردیف `supplier_profiles` را حذف می‌کرد و نقشِ
 * Laratrust سرِ جایش می‌ماند — یعنی کاربری بدون هیچ پروفایلِ تأمین‌کننده که
 * همچنان از `role.supplier` رد می‌شد و کلِ پنل خودسرویس برایش باز بود. دو مسیرِ
 * دیگر (`AdminSupplierController::reject` و `SyncUserRolesAction`) نقش را جدا
 * می‌کنند؛ این یکی جا مانده بود.
 */
class SupplierWithdrawalTest extends TestCase
{
    use DatabaseTransactions;

    private function approvedSupplier(): User
    {
        $role = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);
        $user = User::factory()->create();
        $user->addRole($role);

        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $user->id]);
        $profile->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED])->save();

        return $user;
    }

    private function factoryFor(User $supplier): Factory
    {
        return Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه '.Str::random(4),
            Factory::SLUG => 'f-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
    }

    private function orderFor(User $supplier, string $status): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'WD-'.Str::random(10),
            Order::STATUS => $status,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return $order;
    }

    #[Test]
    public function withdrawing_removes_the_supplier_role_not_just_the_profile(): void
    {
        $user = $this->approvedSupplier();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile/supplier')->assertOk();

        $this->assertNull(SupplierProfile::where(SupplierProfile::USER_ID, $user->id)->first());
        $this->assertFalse(
            $user->fresh()->hasRole('supplier'),
            'نقش سوپلایر باید همراه پروفایل جدا شود، وگرنه دسترسی بدون پروفایل باقی می‌ماند.'
        );
    }

    #[Test]
    public function the_self_service_panel_closes_after_withdrawing(): void
    {
        $user = $this->approvedSupplier();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/supplier/dashboard')->assertOk();

        $this->deleteJson('/api/profile/supplier')->assertOk();

        $this->getJson('/api/profile/supplier/dashboard')->assertForbidden();
    }

    #[Test]
    public function withdrawing_is_blocked_while_a_factory_is_still_registered(): void
    {
        // کارخانه‌ای که می‌ماند یعنی قیمت و نرخ حملِ زنده بدون هیچ متولی — و با
        // رفتنِ نقش، خودِ کاربر هم دیگر نمی‌تواند اصلاحش کند.
        $user = $this->approvedSupplier();
        $this->factoryFor($user);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile/supplier')->assertStatus(422);

        $this->assertNotNull(SupplierProfile::where(SupplierProfile::USER_ID, $user->id)->first());
        $this->assertTrue($user->fresh()->hasRole('supplier'));
    }

    #[Test]
    public function withdrawing_is_blocked_while_an_order_is_still_open(): void
    {
        // فلگ‌های فرمول (`trans_commission`/`trans_vat`) هنگام پرداخت از همین
        // پروفایل خوانده می‌شوند؛ نبودنش یعنی fallback به `?? true` و تسویه با
        // فرمولی که تأمین‌کننده انتخاب نکرده بود.
        $user = $this->approvedSupplier();
        $this->orderFor($user, Order::STATUS_PROCESSING);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile/supplier')->assertStatus(422);
        $this->assertTrue($user->fresh()->hasRole('supplier'));
    }

    #[Test]
    public function a_completed_order_does_not_block_withdrawing(): void
    {
        $user = $this->approvedSupplier();
        $this->orderFor($user, Order::STATUS_DELIVERED);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile/supplier')->assertOk();
        $this->assertFalse($user->fresh()->hasRole('supplier'));
    }
}

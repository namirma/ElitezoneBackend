<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Permission;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * قرارداد fail-closed برای EnsureAdminHasPermission.
 *
 * پیش از این، «ادمینِ بدون هیچ مجوز user-level» دسترسیِ کامل داشت. این دقیقاً
 * وارونهٔ نیتِ AdminManagerController::update بود که با permissions=[] قصدِ
 * سلبِ دسترسی دارد — یعنی محدودکردنِ یک ادمین، او را به سوپرادمین تبدیل می‌کرد
 * و مسیرهای manage-financial (تسویهٔ بانکی Podium و شارژ دستیِ کیف‌پول) را
 * برایش باز می‌گذاشت.
 */
class AdminPermissionGateTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $user;
    }

    private function permissionId(string $name): int
    {
        return Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => AdminPermissionSeeder::PERMISSIONS[$name]],
        )->id;
    }

    #[Test]
    public function admin_with_zero_permissions_is_denied_on_a_gated_route(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/financial/health')->assertForbidden();
        $this->getJson('/api/admin/banners')->assertForbidden();
    }

    #[Test]
    public function admin_with_all_permissions_passes_the_gate(): void
    {
        Sanctum::actingAs($this->grantAllAdminPermissions($this->admin()));

        $this->getJson('/api/admin/financial/health')->assertOk();
        $this->getJson('/api/admin/banners')->assertOk();
    }

    #[Test]
    public function permissions_are_scoped_per_group(): void
    {
        $admin = $this->admin();
        $admin->syncPermissions([$this->permissionId('manage-content')]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/banners')->assertOk();
        $this->getJson('/api/admin/financial/health')->assertForbidden();
    }

    #[Test]
    public function revoking_every_permission_actually_revokes_access(): void
    {
        $admin = $this->grantAllAdminPermissions($this->admin());
        $target = $this->grantAllAdminPermissions($this->admin());

        Sanctum::actingAs($admin);

        // permissions=[] در پنل یعنی «همه را سلب کن» — تا پیش از این fix
        // نتیجه‌اش دسترسیِ کامل بود، نه سلب.
        $this->putJson("/api/admin/admins/{$target->id}", ['permissions' => []])
            ->assertOk();

        $this->assertSame(0, $target->fresh()->permissions()->count());

        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/admin/financial/health')->assertForbidden();
    }

    #[Test]
    public function a_route_without_a_permission_gate_stays_reachable(): void
    {
        // /admin/dashboard فقط role.admin دارد و عمداً سطح‌بندی نشده — fail-closed
        // شدنِ میدل‌ور نباید مسیرهای بدونِ گیت را ببندد.
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertOk();
    }
}

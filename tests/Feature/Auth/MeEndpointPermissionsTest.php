<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * `GET /api/user` باید نقش‌ها و — برای ادمین — مجوزهایش را برگرداند.
 *
 * از وقتی EnsureAdminHasPermission به fail-closed تغییر کرد، پنل ادمین باید
 * بداند کدام بخش‌ها برای این ادمین باز است؛ در غیر این صورت تنها راهِ کشفِ
 * دسترسی، صدا زدنِ endpointها و گرفتنِ ۴۰۳ بود.
 */
class MeEndpointPermissionsTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    #[Test]
    public function an_admin_sees_their_own_roles_and_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);

        Sanctum::actingAs($admin);

        $data = $this->getJson('/api/user')->assertOk()->json('data');

        $this->assertContains('admin', $data['roles']);
        $this->assertContains('manage-financial', $data['permissions']);
        $this->assertContains('manage-content', $data['permissions']);
    }

    #[Test]
    public function a_restricted_admin_only_sees_the_permissions_they_hold(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        $admin->syncPermissions(
            \Laratrust\Models\Permission::where('name', 'manage-content')->pluck('id')->all()
        );

        Sanctum::actingAs($admin);

        $permissions = $this->getJson('/api/user')->assertOk()->json('data.permissions');

        $this->assertSame(['manage-content'], $permissions);
    }

    #[Test]
    public function a_non_admin_never_sees_a_permissions_key(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $data = $this->getJson('/api/user')->assertOk()->json('data');

        $this->assertArrayNotHasKey('permissions', $data);
    }
}

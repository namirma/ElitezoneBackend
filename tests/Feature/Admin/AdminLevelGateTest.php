<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * سطحِ اختیارِ ادمین — لایه‌ی دوم، کنارِ مجوزها.
 *
 * نکته‌ی محوریِ این فایل: هر ادمینِ این تست‌ها **همه‌ی مجوزها** را دارد. پس هر
 * ۴۰۳ای که می‌بینیم فقط می‌تواند از سطح آمده باشد، نه از مجوز — وگرنه تست
 * می‌توانست با گیتِ اشتباهی سبز شود و چیزی را ثابت نکند.
 */
class AdminLevelGateTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function adminAtLevel(int $level): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);
        $this->setAdminLevel($user, $level);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    #[Test]
    public function the_owner_reaches_the_admin_management_group(): void
    {
        $this->adminAtLevel(User::ADMIN_LEVEL_OWNER);

        $this->getJson('/api/admin/admins')->assertOk();
    }

    #[Test]
    public function a_manager_cannot_reach_the_admin_management_group(): void
    {
        $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);

        // مجوزِ manage-users را دارد؛ تنها چیزی که جلویش را می‌گیرد سطح است.
        $this->getJson('/api/admin/admins')
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_LEVEL_TOO_LOW');
    }

    #[Test]
    public function an_agent_cannot_reach_the_admin_management_group(): void
    {
        $this->adminAtLevel(User::ADMIN_LEVEL_AGENT);

        $this->getJson('/api/admin/admins')->assertForbidden();
    }

    #[Test]
    public function only_the_owner_may_write_site_settings(): void
    {
        $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);
        $this->putJson('/api/admin/settings', ['settings' => ['support_phone' => '02100000000']])
            ->assertForbidden();

        // خواندن عمداً باز است — مدیرِ محتوا باید مقادیرِ فعلی را ببیند.
        $this->getJson('/api/admin/settings')->assertOk();

        $this->adminAtLevel(User::ADMIN_LEVEL_OWNER);
        $this->putJson('/api/admin/settings', ['settings' => ['support_phone' => '02100000000']])
            ->assertOk();
    }

    #[Test]
    public function an_agent_cannot_move_money_but_a_manager_can_reach_the_endpoint(): void
    {
        $this->adminAtLevel(User::ADMIN_LEVEL_AGENT);
        $this->getJson('/api/v1/podium/balances')->assertForbidden();

        // برای مدیر، این مسیر دیگر به‌خاطرِ سطح رد نمی‌شود. پاسخِ نهایی به سرویسِ
        // بیرونی بستگی دارد، پس فقط «۴۰۳ نبودن» ادعا می‌شود — تستِ سطح نباید به
        // در دسترس بودنِ api.pod.ir گره بخورد.
        $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);
        $this->assertNotSame(403, $this->getJson('/api/v1/podium/balances')->getStatusCode());
    }

    #[Test]
    public function an_admin_without_a_level_is_refused(): void
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);
        // صریحاً به حالتِ «سطحِ نامعلوم» برگردانده می‌شود — ردیفی که از راهی جز
        // مسیرِ عادی ساخته شده باشد. باید رد شود، نه اینکه باز فرض شود.
        $user->forceFill([User::ADMIN_LEVEL => null])->save();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/admin/admins')->assertForbidden();
    }

    #[Test]
    public function the_level_helper_treats_a_smaller_number_as_more_authority(): void
    {
        $owner = User::factory()->make([User::ADMIN_LEVEL => User::ADMIN_LEVEL_OWNER]);
        $agent = User::factory()->make([User::ADMIN_LEVEL => User::ADMIN_LEVEL_AGENT]);

        // مالک هر سقفی را پاس می‌کند…
        $this->assertTrue($owner->hasAdminLevel(User::ADMIN_LEVEL_OWNER));
        $this->assertTrue($owner->hasAdminLevel(User::ADMIN_LEVEL_AGENT));
        $this->assertTrue($owner->isOwnerAdmin());

        // …و کارشناس فقط سقفِ خودش را.
        $this->assertFalse($agent->hasAdminLevel(User::ADMIN_LEVEL_OWNER));
        $this->assertTrue($agent->hasAdminLevel(User::ADMIN_LEVEL_AGENT));
        $this->assertFalse($agent->isOwnerAdmin());
    }
}

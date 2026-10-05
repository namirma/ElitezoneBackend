<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\StoreAdminRequest;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Laratrust\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * قراردادِ سطحِ اختیار در ساخت و ویرایشِ مدیر (`/api/admin/admins`).
 *
 * تا ۲۰۲۶-۱۰-۰۳ مسیرِ ساخت هیچ‌وقت `admin_level` را نمی‌نوشت: ادمینی که از پنل
 * ساخته می‌شد NULL می‌ماند و چون `EnsureAdminHasLevel` fail-closed است، روی هر
 * مسیرِ `admin.level:N` ۴۰۳ می‌گرفت. تستِ اول همان ایراد را از سرِ مسیرِ واقعی
 * می‌سنجد: ساختن از راهِ API، بعد رسیدن به یک مسیرِ سطح ۲ با همان ادمین.
 */
class AdminManagerLevelTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function adminAtLevel(int $level): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);

        return $this->setAdminLevel($user, $level);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAdminPayload(array $overrides = []): array
    {
        return array_merge([
            'mobile' => '0999'.fake()->unique()->numerify('#######'),
            'password' => 'Secret@123',
            'national_code' => fake()->unique()->numerify('##########'),
            'birth_date' => '13700101',
            'name' => 'آزمون',
            'family' => 'سطح',
            'admin_level' => User::ADMIN_LEVEL_MANAGER,
        ], $overrides);
    }

    #[Test]
    public function an_admin_created_from_the_panel_can_reach_a_level_two_route(): void
    {
        $this->actingAs($this->adminAtLevel(User::ADMIN_LEVEL_OWNER), 'sanctum');

        $id = $this->postJson('/api/admin/admins', $this->newAdminPayload())
            ->assertCreated()
            ->json('data.id');

        // همان ادمین، بدونِ هیچ دست‌کاریِ دیگری، به یک مسیرِ سطح ۲ می‌رسد.
        $target = User::factory()->create();
        $this->actingAs(User::findOrFail($id), 'sanctum');
        $this->postJson("/api/admin/users/{$target->id}/toggle-status")->assertOk();
    }

    #[Test]
    public function the_level_is_stored_returned_and_logged_on_create(): void
    {
        $this->actingAs($this->adminAtLevel(User::ADMIN_LEVEL_OWNER), 'sanctum');

        $id = $this->postJson('/api/admin/admins', $this->newAdminPayload(['admin_level' => User::ADMIN_LEVEL_AGENT]))
            ->assertCreated()
            ->assertJsonPath('data.admin_level', User::ADMIN_LEVEL_AGENT)
            ->json('data.id');

        $created = User::findOrFail($id);
        $this->assertSame(User::ADMIN_LEVEL_AGENT, (int) $created->{User::ADMIN_LEVEL});

        $log = Activity::forSubject($created)->where('description', 'مدیر سیستم جدید ساخته شد.')->firstOrFail();
        $this->assertSame(User::ADMIN_LEVEL_AGENT, $log->properties['admin_level']);
    }

    #[Test]
    public function a_level_is_required_and_must_be_one_of_the_three(): void
    {
        $this->actingAs($this->adminAtLevel(User::ADMIN_LEVEL_OWNER), 'sanctum');

        $payload = $this->newAdminPayload();
        unset($payload['admin_level']);
        $this->postJson('/api/admin/admins', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('admin_level');

        foreach ([0, 4, 'owner', 1.5] as $bad) {
            $this->postJson('/api/admin/admins', $this->newAdminPayload(['admin_level' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('admin_level');
        }
    }

    #[Test]
    public function nobody_can_grant_a_level_above_their_own(): void
    {
        // امروز فقط مدیر ارشد به گروهِ مدیران می‌رسد (`admin.level:1`)، پس این قاعده از
        // راهِ HTTP دست‌یافتنی نیست. مستقیم سنجیده می‌شود تا روزی که گروه برای «مدیر»
        // باز شود، جلوی ارتقا از قبل ایستاده باشد.
        $manager = $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);
        $rules = (new StoreAdminRequest)->setUserResolver(fn () => $manager)->rules();

        $this->assertTrue(Validator::make($this->newAdminPayload(['admin_level' => User::ADMIN_LEVEL_OWNER]), $rules)
            ->errors()->has('admin_level'));
        $this->assertFalse(Validator::make($this->newAdminPayload(['admin_level' => User::ADMIN_LEVEL_AGENT]), $rules)
            ->errors()->has('admin_level'));

        $this->assertSame([1, 2, 3], User::factory()->make([User::ADMIN_LEVEL => 1])->grantableAdminLevels());
        $this->assertSame([2, 3], $manager->grantableAdminLevels());
        $this->assertSame([3], User::factory()->make([User::ADMIN_LEVEL => 3])->grantableAdminLevels());
        // بی‌سطح هیچ سطحی نمی‌دهد — fail-closed مثلِ خودِ گیت.
        $this->assertSame([], User::factory()->make([User::ADMIN_LEVEL => null])->grantableAdminLevels());
    }

    #[Test]
    public function an_owner_can_change_another_admins_level_and_it_takes_effect_at_once(): void
    {
        $owner = $this->adminAtLevel(User::ADMIN_LEVEL_OWNER);
        $manager = $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);
        $target = User::factory()->create();

        $this->actingAs($owner, 'sanctum');
        $this->putJson("/api/admin/admins/{$manager->id}", ['admin_level' => User::ADMIN_LEVEL_AGENT])
            ->assertOk()
            ->assertJsonPath('data.admin_level', User::ADMIN_LEVEL_AGENT);

        $log = Activity::forSubject($manager)->where('description', 'اطلاعات مدیر سیستم ویرایش شد.')->firstOrFail();
        // MySQL 8 کلیدهای JSON را به ترتیبِ خودش (طول، بعد الفبا: «to» پیش از «from») برمی‌گرداند و
        // MariaDB متنِ خام را؛ assertSame روی آرایه ترتیب را هم می‌سنجد و فقط روی CI قرمز می‌شد.
        $this->assertEquals(['from' => 2, 'to' => 3], $log->properties['admin_level']);

        // سطح در هر درخواست از ستون خوانده می‌شود: تنزل بدونِ خروج و ورودِ دوباره اثر دارد.
        $this->actingAs($manager->refresh(), 'sanctum');
        $this->postJson("/api/admin/users/{$target->id}/toggle-status")
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_LEVEL_TOO_LOW');
    }

    #[Test]
    public function an_admin_cannot_change_their_own_level_but_may_resend_it_unchanged(): void
    {
        $owner = $this->adminAtLevel(User::ADMIN_LEVEL_OWNER);
        $this->actingAs($owner, 'sanctum');

        // اگر مدیر ارشد بتواند خودش را تنزل دهد، آخرین مدیر ارشد پنل را از دست می‌دهد و
        // راهِ برگشت فقط دست‌بردن در دیتابیس است.
        $this->putJson("/api/admin/admins/{$owner->id}", ['admin_level' => User::ADMIN_LEVEL_AGENT])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('admin.levels.self_demote'));
        $this->assertSame(User::ADMIN_LEVEL_OWNER, (int) $owner->refresh()->{User::ADMIN_LEVEL});

        // فرمِ ویرایش سطحِ فعلی را پیش‌پر می‌فرستد؛ ویرایشِ نامِ خود نباید به‌خاطرِ آن رد شود.
        $this->putJson("/api/admin/admins/{$owner->id}", ['admin_level' => User::ADMIN_LEVEL_OWNER, 'name' => 'تازه'])
            ->assertOk();
    }

    #[Test]
    public function removing_the_admin_role_clears_the_level(): void
    {
        $this->actingAs($this->adminAtLevel(User::ADMIN_LEVEL_OWNER), 'sanctum');
        $manager = $this->adminAtLevel(User::ADMIN_LEVEL_MANAGER);

        $this->deleteJson("/api/admin/admins/{$manager->id}")->assertOk();

        // قاعده‌ی ستون: NULL یعنی «ادمین نیست». سطحِ جامانده روی کاربرِ عادی، اگر روزی
        // نقش دوباره داده شود، بی‌صدا همان اختیارِ قبلی را برمی‌گرداند.
        $this->assertNull($manager->refresh()->{User::ADMIN_LEVEL});
    }

    #[Test]
    public function the_admins_list_shows_every_admins_level_including_a_missing_one(): void
    {
        $owner = $this->adminAtLevel(User::ADMIN_LEVEL_OWNER);
        $agent = $this->adminAtLevel(User::ADMIN_LEVEL_AGENT);
        $broken = $this->adminAtLevel(User::ADMIN_LEVEL_AGENT);
        $broken->forceFill([User::ADMIN_LEVEL => null])->save();

        $this->actingAs($owner, 'sanctum');
        $rows = collect($this->getJson('/api/admin/admins?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(User::ADMIN_LEVEL_AGENT, $rows[$agent->id]['admin_level']);
        // بی‌سطح هم باید دیده شود (null، نه کلیدِ غایب) تا پنل بتواند هشدارش را نشان دهد.
        $this->assertArrayHasKey('admin_level', $rows[$broken->id]);
        $this->assertNull($rows[$broken->id]['admin_level']);

        $this->getJson("/api/admin/admins/{$agent->id}")->assertJsonPath('data.admin_level', User::ADMIN_LEVEL_AGENT);
    }

    #[Test]
    public function the_bootstrap_seeder_makes_its_admin_an_owner_only_when_the_level_is_missing(): void
    {
        // روی دیتابیسِ تازه backfillِ مایگریشن پیش از سیدر اجرا می‌شود؛ ادمینِ سیدر بی‌سطح
        // می‌ماند و هیچ‌کس به صفحه‌ی مدیران نمی‌رسید. همان حالت این‌جا ساخته می‌شود.
        User::query()->where(User::MOBILE, '09100000009')->update([User::ADMIN_LEVEL => null]);

        $this->seed(AdminSeeder::class);
        $admin = User::where(User::MOBILE, '09100000009')->firstOrFail();
        $this->assertSame(User::ADMIN_LEVEL_OWNER, (int) $admin->{User::ADMIN_LEVEL});

        // تنزلِ عمدی با اجرای دوباره‌ی سیدر برنمی‌گردد.
        $admin->forceFill([User::ADMIN_LEVEL => User::ADMIN_LEVEL_AGENT])->save();
        $this->seed(AdminSeeder::class);
        $this->assertSame(User::ADMIN_LEVEL_AGENT, (int) $admin->refresh()->{User::ADMIN_LEVEL});
    }
}

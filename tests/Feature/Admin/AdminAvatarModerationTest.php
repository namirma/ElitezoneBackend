<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\SupportIdentity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * صفِ تأییدِ تصویرِ ادمین.
 *
 * ادعای مرکزی: **تصویرِ تأییدنشده هرگز به مشتری نمی‌رسد.** تصویرِ ادمین در تیکت
 * کنارِ نامش دیده می‌شود، پس چهره‌ی برند است؛ اگر مثلِ کاربرِ عادی مستقیم منتشر
 * شود، یک تصویرِ نامناسب تا لحظه‌ی گزارش‌شدن روی هر گفت‌وگوی پشتیبانی می‌نشیند.
 */
class AdminAvatarModerationTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function admin(int $level): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);
        $this->setAdminLevel($user, $level);

        return $user;
    }

    #[Test]
    public function a_sub_admins_upload_lands_in_the_queue_and_is_not_published(): void
    {
        Storage::fake('public');
        $admin = $this->admin(User::ADMIN_LEVEL_MANAGER);
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertOk()->assertJsonPath('data.pending', true);

        $profile = UserProfile::where(UserProfile::USER_ID, $admin->id)->first();

        $this->assertNotNull($profile->{UserProfile::AVATAR_PENDING}, 'باید در صف بنشیند');
        $this->assertNull($profile->{UserProfile::AVATAR}, 'نباید منتشر شده باشد');
    }

    #[Test]
    public function the_owner_publishes_directly_without_a_queue(): void
    {
        Storage::fake('public');
        $owner = $this->admin(User::ADMIN_LEVEL_OWNER);
        $this->actingAs($owner, 'sanctum');

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('owner.jpg', 300, 300),
        ])->assertOk()->assertJsonPath('data.pending', false);

        $profile = UserProfile::where(UserProfile::USER_ID, $owner->id)->first();

        // کسی بالادستِ مالک نیست که تأیید کند؛ صفی که تأییدکننده ندارد یعنی قفلِ دائمی.
        $this->assertNotNull($profile->{UserProfile::AVATAR});
        $this->assertNull($profile->{UserProfile::AVATAR_PENDING});
    }

    #[Test]
    public function approving_publishes_the_pending_image(): void
    {
        Storage::fake('public');
        $sub = $this->admin(User::ADMIN_LEVEL_MANAGER);
        $this->actingAs($sub, 'sanctum');
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertOk();

        $owner = $this->admin(User::ADMIN_LEVEL_OWNER);
        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/admin/avatar-requests/{$sub->id}/approve")->assertOk();

        $profile = UserProfile::where(UserProfile::USER_ID, $sub->id)->first();
        $this->assertNotNull($profile->{UserProfile::AVATAR});
        $this->assertNull($profile->{UserProfile::AVATAR_PENDING});
    }

    #[Test]
    public function rejecting_clears_the_queue_and_records_the_reason(): void
    {
        Storage::fake('public');
        $sub = $this->admin(User::ADMIN_LEVEL_MANAGER);
        $this->actingAs($sub, 'sanctum');
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertOk();

        $owner = $this->admin(User::ADMIN_LEVEL_OWNER);
        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/admin/avatar-requests/{$sub->id}/reject", ['reason' => 'تصویر نامناسب است.'])
            ->assertOk();

        $profile = UserProfile::where(UserProfile::USER_ID, $sub->id)->first();
        $this->assertNull($profile->{UserProfile::AVATAR_PENDING});
        $this->assertNull($profile->{UserProfile::AVATAR}, 'ردشدن نباید چیزی منتشر کند');
        $this->assertSame('تصویر نامناسب است.', $profile->{UserProfile::AVATAR_REJECTION_REASON});
    }

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        Storage::fake('public');
        $sub = $this->admin(User::ADMIN_LEVEL_MANAGER);
        $this->actingAs($sub, 'sanctum');
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertOk();

        $this->actingAs($this->admin(User::ADMIN_LEVEL_OWNER), 'sanctum');

        // دلیلِ رد برای خودِ ادمین نمایش داده می‌شود؛ ردِ بدونِ دلیل یعنی او
        // نمی‌داند چه چیزی را باید عوض کند.
        $this->postJson("/api/admin/avatar-requests/{$sub->id}/reject", [])
            ->assertStatus(422);
    }

    #[Test]
    public function a_manager_cannot_moderate_avatars(): void
    {
        $this->actingAs($this->admin(User::ADMIN_LEVEL_MANAGER), 'sanctum');

        $this->getJson('/api/admin/avatar-requests')->assertForbidden();
    }

    #[Test]
    public function support_identity_never_exposes_a_pending_image(): void
    {
        Storage::fake('public');
        $sub = $this->admin(User::ADMIN_LEVEL_MANAGER);
        UserProfile::create([
            UserProfile::USER_ID => $sub->id,
            UserProfile::NAME => 'سجاد',
            UserProfile::FAMILY => 'زرسنج',
            UserProfile::AVATAR_PENDING => 'avatars/not-approved.jpg',
        ]);

        $identity = SupportIdentity::forAdmin($sub->refresh());

        $this->assertNull($identity['avatar_url'], 'تصویرِ در انتظار نباید بیرون برود');
        $this->assertSame('سجاد زرسنج', $identity['name']);
    }

    #[Test]
    public function support_identity_renders_name_with_the_area_in_parentheses(): void
    {
        $admin = $this->admin(User::ADMIN_LEVEL_MANAGER);
        UserProfile::create([
            UserProfile::USER_ID => $admin->id,
            UserProfile::NAME => 'سجاد',
            UserProfile::FAMILY => 'زرسنج',
        ]);

        $identity = SupportIdentity::forAdmin($admin->refresh());

        // این ادمین همه‌ی مجوزها را دارد، پس تخصصی‌ترین حوزه انتخاب می‌شود.
        $this->assertSame('پشتیبان بخش مالی', $identity['area']);
        $this->assertSame('سجاد زرسنج (پشتیبان بخش مالی)', $identity['display_name']);
    }

    #[Test]
    public function an_admin_without_a_name_falls_back_to_the_generic_label(): void
    {
        $admin = $this->admin(User::ADMIN_LEVEL_MANAGER);

        $identity = SupportIdentity::forAdmin($admin);

        // هرگز نباید به شماره‌ی موبایلِ کارمند برگردد — آن نشتِ اطلاعات است.
        $this->assertSame(__('ticket.support_author'), $identity['name']);
        $this->assertStringNotContainsString($admin->mobile, $identity['display_name']);
    }
}

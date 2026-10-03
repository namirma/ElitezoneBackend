<?php

namespace Tests\Feature\Profile;

use App\Jobs\Notification\SendSmsJob;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnablesVerificationMethods;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * دستورِ علی (۱۴۰۵/۰۶/۳۱): هیچ چیزِ تأییدی بی‌صدا عوض نمی‌شود — وقتی حکمِ
 * «الیت‌یارِ تأییدشده» برای یک کاربر عوض می‌شود، پیامک + اعلانِ درون‌برنامه‌ای
 * + ردِ ممیزی. حکم = `User::isVerifiedEliteYAR()`، نه عضویتِ خام: تغییری که حکم
 * را عوض نمی‌کند (تأییدِ دستی برای عضوِ سازه ۹۰) پیامک ندارد.
 */
class EliteYARVerificationNotificationTest extends TestCase
{
    use DatabaseTransactions, EnablesVerificationMethods, GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendSmsJob::class]);
        config(['services.saze90.enabled' => true, 'services.saze90.base_url' => 'https://www.saze90.com']);
    }

    private function eliteYAR(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'الیت یار']));

        return $user;
    }

    private function templateKeys(): array
    {
        $keys = [];
        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) use (&$keys) {
            $keys[] = (fn () => $this->templateKey)->call($job);

            return true;
        });

        return $keys;
    }

    // ── سازه ۹۰ ─────────────────────────────────────────────────────────────

    #[Test]
    public function becoming_a_member_sends_the_verified_sms_and_an_in_app_notification(): void
    {
        Http::fake(['*get-certificate*' => Http::response(['message' => 'serial', 'status' => 200], 200)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234501']);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', true);

        $this->assertSame(['elite_yar_verified'], $this->templateKeys());
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $user->id)->count());
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'description' => 'کاربر به‌عنوان الیت یار تأیید شد.']);
    }

    #[Test]
    public function losing_membership_sends_the_unverified_sms(): void
    {
        Http::fake(['*get-certificate*' => Http::response(['message' => false, 'status' => 200], 200)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234502', User::IS_SAZE90_USER => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', false);

        $this->assertSame(['elite_yar_unverified'], $this->templateKeys());
    }

    #[Test]
    public function a_re_confirmed_member_gets_no_sms(): void
    {
        Http::fake(['*get-certificate*' => Http::response(['message' => 'serial', 'status' => 200], 200)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234503', User::IS_SAZE90_USER => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $user->id)->count());
    }

    #[Test]
    public function an_outage_sends_nothing(): void
    {
        Http::fake(['*get-certificate*' => Http::response('', 500)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234504', User::IS_SAZE90_USER => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk();

        Bus::assertNotDispatched(SendSmsJob::class);
    }

    #[Test]
    public function a_member_who_is_not_an_elite_yar_gets_no_verification_sms(): void
    {
        // کارفرمایی که اتفاقاً کاربرِ سازه ۹۰ است الیت یار نیست — «تأیید شدید» برایش بی‌معنی است.
        Http::fake(['*get-certificate*' => Http::response(['message' => 'serial', 'status' => 200], 200)]);
        $employer = User::factory()->create([User::MOBILE => '09121234505']);
        $employer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']));
        Sanctum::actingAs($employer);

        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', true);

        Bus::assertNotDispatched(SendSmsJob::class);
    }

    // ── تأییدِ دستیِ ادمین ───────────────────────────────────────────────────

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);
        $this->setAdminLevel($user, User::ADMIN_LEVEL_OWNER);

        return $user;
    }

    #[Test]
    public function the_admin_verifying_documents_sends_the_verified_sms_when_it_changes_the_verdict(): void
    {
        $this->enableVerificationMethod('education');
        $user = $this->eliteYAR([User::MOBILE => '09121234506']);
        EngineerProfile::create([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/users/{$user->id}/verify-elite-yar")->assertOk();
        $this->assertSame(['elite_yar_verified'], $this->templateKeys());

        $this->postJson("/api/admin/users/{$user->id}/unverify-elite-yar")->assertOk();
        $this->assertSame(['elite_yar_verified', 'elite_yar_unverified'], $this->templateKeys());
    }

    #[Test]
    public function the_admin_verifying_documents_of_a_saze90_member_sends_nothing_because_the_verdict_did_not_change(): void
    {
        $this->enableVerificationMethod('education');
        $user = $this->eliteYAR([User::MOBILE => '09121234507', User::IS_SAZE90_USER => true]);
        EngineerProfile::create([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/users/{$user->id}/verify-elite-yar")->assertOk();

        Bus::assertNotDispatched(SendSmsJob::class);
    }
}

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

    // ── خبرِ هولد به کارفرما (مدیریت، دورِ پنجم، پرسشِ ۶) ──────────────────────

    /** @return list<array{0: string, 1: ?string}> [موبایل، کلیدِ قالب] */
    private function sentSms(): array
    {
        $sent = [];
        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) use (&$sent) {
            $sent[] = (fn () => [$this->mobile, $this->templateKey])->call($job);

            return true;
        });

        return $sent;
    }

    private function employerOf(User $eliteYAR, string $mobile, string $status): User
    {
        $employer = User::factory()->create([User::MOBILE => $mobile]);
        $employer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']));
        $employer->technicalEliteYARs()->attach($eliteYAR->id, ['status' => $status]);

        return $employer;
    }

    #[Test]
    public function a_hold_tells_each_approved_employer_and_nobody_else(): void
    {
        Http::fake(['*get-certificate*' => Http::response(['message' => false, 'status' => 200], 200)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234511', User::IS_SAZE90_USER => true]);
        $approved = $this->employerOf($user, '09121234512', 'approved');
        $pending = $this->employerOf($user, '09121234513', 'pending');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', false);

        $this->assertEqualsCanonicalizing([
            ['09121234511', 'elite_yar_unverified'],
            ['09121234512', 'elite_yar_hold_employer'],
        ], $this->sentSms());
        $notice = UserNotification::where(UserNotification::USER_ID, $approved->id)->sole();
        $this->assertStringContainsString('الیت یار جدید معرفی کنید', (string) $notice->{UserNotification::BODY});
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $pending->id)->count());
    }

    #[Test]
    public function lifting_the_hold_tells_the_approved_employers_too(): void
    {
        Http::fake(['*get-certificate*' => Http::response(['message' => 'serial', 'status' => 200], 200)]);
        $user = $this->eliteYAR([User::MOBILE => '09121234514']);
        $approved = $this->employerOf($user, '09121234515', 'approved');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', true);

        $this->assertEqualsCanonicalizing([
            ['09121234514', 'elite_yar_verified'],
            ['09121234515', 'elite_yar_restored_employer'],
        ], $this->sentSms());
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $approved->id)->count());
    }

    #[Test]
    public function a_legacy_engineer_elite_yar_is_put_on_hold_with_the_same_messages(): void
    {
        // الیت‌یارِ قدیمی با نقشِ engineer (بی‌نقشِ elite_yar) هم هولد می‌شود — `User::isEliteYAR()`.
        Http::fake(['*get-certificate*' => Http::response(['message' => false, 'status' => 200], 200)]);
        $user = User::factory()->create([User::MOBILE => '09121234516', User::IS_SAZE90_USER => true]);
        $user->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'مهندس']));
        $this->employerOf($user, '09121234517', 'approved');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk();

        $this->assertEqualsCanonicalizing([
            ['09121234516', 'elite_yar_unverified'],
            ['09121234517', 'elite_yar_hold_employer'],
        ], $this->sentSms());
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

<?php

namespace Tests\Feature\Profile;

use App\Models\EmployerEliteYAR;
use App\Models\EngineerProfile;
use App\Models\JobExperience;
use App\Models\Setting;
use App\Models\User;
use App\Services\Profile\EliteYARVerificationMethods;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * سیاستِ لانچ (مدیریت، ۱۴۰۵/۰۶/۳۰): تأیید الیت یار فقط از راهِ عضویت در سازه ۹۰؛
 * تحصیلی و شغلی «به‌زودی» و از تنظیماتِ ادمین قابلِ روشن‌شدن.
 *
 * هر تست همان چیزی را می‌سنجد که پیش از این تغییر اشتباه بود: محمولِ
 * «تأییدشده» پنج نسخه داشت و سه گیتِ واقعی سازه ۹۰ را نمی‌شمردند.
 */
class EliteYARVerificationPolicyTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function setMethods(bool $education, bool $job, bool $saze90): void
    {
        foreach ([
            EliteYARVerificationMethods::KEYS['education'] => $education,
            EliteYARVerificationMethods::KEYS['job'] => $job,
            EliteYARVerificationMethods::KEYS['saze90'] => $saze90,
        ] as $key => $on) {
            Setting::updateOrCreate([Setting::KEY => $key], [Setting::VALUE => $on ? '1' : '0']);
        }
    }

    private function eliteYAR(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'الیت یار']));

        return $user;
    }

    private function employer(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']));

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($user);
        $this->setAdminLevel($user, User::ADMIN_LEVEL_OWNER);

        return $user;
    }

    // ── سیاست و پیش‌فرض ──────────────────────────────────────────────────────

    #[Test]
    public function the_launch_default_is_saze90_only(): void
    {
        Setting::whereIn(Setting::KEY, array_values(EliteYARVerificationMethods::KEYS))->delete();
        Cache::flush();

        $this->assertSame(['education' => false, 'job' => false, 'saze90' => true], EliteYARVerificationMethods::enabled());

        // و سایت همان را حل‌شده می‌گیرد — نه رشته‌ی «1»/«0»، نه کلیدِ غایب.
        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJsonPath('data.elite_yar_verification.education', false)
            ->assertJsonPath('data.elite_yar_verification.job', false)
            ->assertJsonPath('data.elite_yar_verification.saze90', true);
    }

    #[Test]
    public function a_saze90_member_without_any_document_profile_is_verified(): void
    {
        // حالتِ عادیِ لانچ: هیچ ردیفِ engineer_profiles وجود ندارد.
        $user = $this->eliteYAR([User::IS_SAZE90_USER => true]);
        Sanctum::actingAs($user);

        $this->assertTrue($user->isVerifiedEliteYAR());
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.is_verified', true);
    }

    #[Test]
    public function admin_document_verification_does_not_count_while_both_document_methods_are_off(): void
    {
        $user = $this->eliteYAR();
        EngineerProfile::create([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
            EngineerProfile::IS_VERIFIED => true,
        ]);

        $this->assertFalse($user->fresh()->isVerifiedEliteYAR());

        $this->setMethods(education: true, job: false, saze90: true);
        $this->assertTrue($user->fresh()->isVerifiedEliteYAR(), 'turning a document method on makes the admin flag count again');
    }

    #[Test]
    public function saze90_membership_stops_counting_when_the_admin_switches_that_method_off(): void
    {
        $user = $this->eliteYAR([User::IS_SAZE90_USER => true]);

        $this->setMethods(education: false, job: false, saze90: false);
        $this->assertFalse($user->fresh()->isVerifiedEliteYAR());
    }

    // ── سه گیتی که سازه ۹۰ را نمی‌شمردند ─────────────────────────────────────

    #[Test]
    public function an_employer_can_invite_a_saze90_verified_elite_yar(): void
    {
        Queue::fake();
        $eliteYAR = $this->eliteYAR([User::MOBILE => '09121110000', User::IS_SAZE90_USER => true]);
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yars/invite', [User::MOBILE => '09121110000'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'invited');

        $this->assertDatabaseHas('employer_elite_yar', [
            EmployerEliteYAR::EMPLOYER_ID => $employer->id,
            EmployerEliteYAR::ELITE_YAR_ID => $eliteYAR->id,
        ]);
    }

    #[Test]
    public function an_employer_cannot_invite_an_unverified_elite_yar(): void
    {
        $this->eliteYAR([User::MOBILE => '09121110001']);
        Sanctum::actingAs($this->employer());

        $this->postJson('/api/profile/elite-yars/invite', [User::MOBILE => '09121110001'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('elite_yar.elite_yar_not_verified'));
    }

    #[Test]
    public function the_employer_list_shows_the_saze90_member_as_verified(): void
    {
        $eliteYAR = $this->eliteYAR([User::IS_SAZE90_USER => true]);
        $employer = $this->employer();
        $employer->technicalEliteYARs()->attach($eliteYAR->id, [EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_APPROVED]);
        Sanctum::actingAs($employer);

        $this->getJson('/api/profile/elite-yars')
            ->assertOk()
            ->assertJsonPath('data.0.is_verified', true);
    }

    // ── اندپوینت‌های ثبتِ روشِ خاموش ──────────────────────────────────────────

    #[Test]
    public function a_disabled_method_rejects_new_submissions_but_keeps_reads_open(): void
    {
        Storage::fake('local');
        $user = $this->eliteYAR();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/job-experiences', [
            JobExperience::TITLE => 'سرپرست کارگاه',
            'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonPath('message', __('elite_yar.method_disabled'));

        $this->postJson('/api/profile/education/fetch', ['national_code' => '0012345678', 'trace_code' => 'ABCD1234'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('elite_yar.method_disabled'));

        $this->getJson('/api/profile/job-experiences')->assertOk();
        $this->getJson('/api/profile/education')->assertOk();

        // روشن‌کردنِ روش، همان درخواست را دوباره می‌پذیرد (این‌جا تا اعتبارسنجیِ خودش می‌رسد).
        $this->setMethods(education: false, job: true, saze90: true);
        $this->postJson('/api/profile/job-experiences', [
            JobExperience::TITLE => 'سرپرست کارگاه',
            'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertStatus(201);
    }

    // ── ادمین ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_admin_can_flip_the_methods_and_only_zero_or_one_is_accepted(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/admin/settings', ['settings' => [
            EliteYARVerificationMethods::KEYS['education'] => '1',
            EliteYARVerificationMethods::KEYS['job'] => '0',
        ]])->assertOk();

        $this->assertSame(['education' => true, 'job' => false, 'saze90' => true], EliteYARVerificationMethods::enabled());

        $this->putJson('/api/admin/settings', ['settings' => [
            EliteYARVerificationMethods::KEYS['saze90'] => 'yes',
        ]])->assertStatus(422);
    }

    #[Test]
    public function manual_verification_is_refused_while_both_document_methods_are_off(): void
    {
        $user = $this->eliteYAR();
        EngineerProfile::create([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
        ]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/users/{$user->id}/verify-elite-yar")
            ->assertStatus(422)
            ->assertJsonPath('message', __('admin.users.manual_verification_disabled'));

        $this->setMethods(education: true, job: false, saze90: true);
        $this->postJson("/api/admin/users/{$user->id}/verify-elite-yar")
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);
    }

    #[Test]
    public function the_admin_user_payload_says_why_someone_is_verified(): void
    {
        $user = $this->eliteYAR([User::IS_SAZE90_USER => true]);
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_saze90_user', true);
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Jobs\Notification\SendInviteSmsJob;
use App\Models\EliteYARSmsInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `POST /profile/elite-yar-invites/sms` — نیمه‌ی دومِ دعوتِ الیت یارِ ثبت‌نام‌نکرده.
 *
 * تا پیش از این، `inviteEliteYAR` پاسخ `not_registered` می‌داد، فرانت اورلیِ
 * دعوت با پیامک را باز می‌کرد، و اندپوینتش ۴۰۴ بود — یعنی جریان همیشه
 * نیمه‌کاره می‌ماند.
 */
class EliteYARSmsInviteTest extends TestCase
{
    use DatabaseTransactions;

    private function employer(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']));

        return $user;
    }

    #[Test]
    public function an_employer_can_invite_an_unregistered_elite_yar_by_sms(): void
    {
        Queue::fake();
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09121112233'])
            ->assertOk()
            ->assertJsonPath('data.remaining_invites', EliteYARSmsInvite::MAX_INVITES - 1);

        $this->assertDatabaseHas('elite_yar_sms_invites', [
            'employer_id' => $employer->id,
            'mobile' => '09121112233',
        ]);

        Queue::assertPushed(SendInviteSmsJob::class);
    }

    #[Test]
    public function the_mobile_is_normalised_before_it_is_stored(): void
    {
        // قاعده‌ی سراسری پروژه `PhoneNumber::to09()` است. بدون نرمال‌سازیِ
        // پیش از اعتبارسنجی، همین شماره هم ۴۲۲ می‌گرفت و هم می‌توانست به‌عنوان
        // دعوتی «تازه» دوباره ثبت شود و سهمیه را دو بار بخورد.
        Queue::fake();
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '+989121112233'])->assertOk();

        $this->assertDatabaseHas('elite_yar_sms_invites', [
            'employer_id' => $employer->id,
            'mobile' => '09121112233',
        ]);
    }

    #[Test]
    public function the_same_number_cannot_be_invited_twice_by_the_same_employer(): void
    {
        Queue::fake();
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09121112233'])->assertOk();

        // قرارداد پاسخی که فرانت انتظار دارد: ۴۲۲ با errors.mobile
        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09121112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');
    }

    #[Test]
    public function an_employer_cannot_invite_their_own_number(): void
    {
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => $employer->mobile])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');
    }

    #[Test]
    public function a_non_employer_cannot_send_invites(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09121112233'])
            ->assertForbidden();
    }

    #[Test]
    public function the_remaining_endpoint_reports_the_quota(): void
    {
        Queue::fake();
        $employer = $this->employer();
        Sanctum::actingAs($employer);

        $this->getJson('/api/profile/elite-yar-invites/remaining')
            ->assertOk()
            ->assertJsonPath('data.remaining_invites', EliteYARSmsInvite::MAX_INVITES);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09121112233'])->assertOk();

        $this->getJson('/api/profile/elite-yar-invites/remaining')
            ->assertJsonPath('data.remaining_invites', EliteYARSmsInvite::MAX_INVITES - 1);
    }

    #[Test]
    public function the_cap_is_enforced(): void
    {
        Queue::fake();
        $employer = $this->employer();

        for ($i = 0; $i < EliteYARSmsInvite::MAX_INVITES; $i++) {
            EliteYARSmsInvite::create([
                EliteYARSmsInvite::EMPLOYER_ID => $employer->id,
                EliteYARSmsInvite::MOBILE => '0912111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                EliteYARSmsInvite::SENT_AT => now(),
            ]);
        }

        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yar-invites/sms', ['mobile' => '09129998877'])
            ->assertStatus(422);
    }
}

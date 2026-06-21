<?php

namespace Tests\Feature\Profile;

use App\Mail\VerifyEmail;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avatar upload + email management (add/resend/delete) for the profile.
 */
class AvatarAndEmailTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Avatar ──────────────────────────────────────────────────────────────────

    /** @test */
    public function a_user_can_upload_an_avatar_and_gets_a_url(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])
            ->assertOk()
            ->assertJsonStructure(['data' => ['avatar_url']]);

        $path = $user->fresh()->profile->avatar;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    /** @test */
    public function the_avatar_must_be_an_allowed_image_type(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    /** @test */
    public function uploading_a_new_avatar_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $old = $user->fresh()->profile->avatar;

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg')])->assertOk();

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($user->fresh()->profile->avatar);
    }

    // ─── Email ───────────────────────────────────────────────────────────────────

    /** @test */
    public function setting_an_email_stores_it_unverified_and_sends_a_link(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/email', ['email' => 'new@example.com'])->assertOk();

        $profile = $user->fresh()->profile;
        $this->assertSame('new@example.com', $profile->email);
        $this->assertNull($profile->email_verified_at);
        $this->assertNotNull($profile->email_verification_token);
        Mail::assertQueued(VerifyEmail::class);
    }

    /** @test */
    public function resend_fails_when_there_is_no_pending_email(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/email/resend')->assertStatus(422);
    }

    /** @test */
    public function resend_reissues_the_link_for_a_pending_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        UserProfile::create([
            UserProfile::USER_ID => $user->id,
            UserProfile::NATIONAL_CODE => $user->national_code,
            UserProfile::EMAIL => 'pending@example.com',
            UserProfile::EMAIL_VERIFICATION_TOKEN => 'old-token',
            UserProfile::EMAIL_VERIFIED_AT => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/email/resend')->assertOk();

        Mail::assertQueued(VerifyEmail::class);
        $this->assertNotSame('old-token', $user->fresh()->profile->email_verification_token);
    }

    /** @test */
    public function resend_fails_when_the_email_is_already_verified(): void
    {
        $user = User::factory()->create();
        UserProfile::create([
            UserProfile::USER_ID => $user->id,
            UserProfile::NATIONAL_CODE => $user->national_code,
            UserProfile::EMAIL => 'done@example.com',
            UserProfile::EMAIL_VERIFIED_AT => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/email/resend')->assertStatus(422);
    }

    /** @test */
    public function deleting_the_email_clears_it(): void
    {
        $user = User::factory()->create();
        UserProfile::create([
            UserProfile::USER_ID => $user->id,
            UserProfile::NATIONAL_CODE => $user->national_code,
            UserProfile::EMAIL => 'gone@example.com',
            UserProfile::EMAIL_VERIFIED_AT => now(),
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile/email')->assertOk();

        $this->assertNull($user->fresh()->profile->email);
        $this->assertNull($user->fresh()->profile->email_verified_at);
    }

    // ─── GET /api/profile exposes the fields the frontend reads ───────────────────

    /** @test */
    public function get_profile_exposes_avatar_url_and_email_state(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')])->assertOk();

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.email_verified_at', null)
            ->assertJsonStructure(['data' => ['profile' => ['avatar', 'email', 'email_verified_at']]]);
    }
}

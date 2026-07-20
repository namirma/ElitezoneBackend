<?php

namespace Tests\Feature\Profile;

use App\Models\EngineerProfile;
use App\Models\JobExperience;
use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the backend slice of the meeting bug-list:
 *   #2 «کاربر سازه ۹۰ هستم» field on the engineer profile
 *   #3 job experiences are editable (with guards)
 *   #4 a user may register multiple job experiences
 *   #5 the 3-step password change has a non-consuming verify step
 */
class ProfileFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function expertUser(): User
    {
        $type = UserType::firstOrCreate(['slug' => 'expert'], ['name' => 'expert', 'icon' => 'user']);
        // The engineer role is seeded in production; the controller attaches it on store.
        Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'Engineer', 'description' => 'Engineer']);

        return User::factory()->create(['type_id' => $type->id]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    }

    // ─── #2 Saze-90 flag is backend-determined (NOT user-settable) ───────────────

    #[Test]
    public function is_saze90_user_cannot_be_set_through_the_engineer_endpoint(): void
    {
        $user = $this->expertUser();
        Sanctum::actingAs($user);

        // Even if the client sends it, the backend ignores it (set only by the saze90 sync).
        $this->postJson('/api/profile/engineer', ['is_saze90_user' => true])->assertOk();

        $this->assertFalse(
            (bool) EngineerProfile::where(EngineerProfile::USER_ID, $user->id)->value('is_saze90_user')
        );
    }

    // ─── #4 multiple job experiences ─────────────────────────────────────────────

    #[Test]
    public function a_user_can_register_multiple_job_experiences(): void
    {
        Storage::fake('local');
        $user = $this->expertUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/job-experiences', ['title' => 'First', 'file' => $this->pdf()])->assertCreated();
        $this->postJson('/api/profile/job-experiences', ['title' => 'Second', 'file' => $this->pdf()])->assertCreated();

        $this->assertSame(2, $user->jobExperiences()->count());
    }

    // ─── Task 1: Jalali date range (1300 .. today) + ongoing (null end_date) ─────

    #[Test]
    public function job_experience_rejects_a_start_date_before_1300(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->expertUser());

        $this->postJson('/api/profile/job-experiences', [
            'title' => 'X', 'start_date' => '1299-12-29', 'file' => $this->pdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    #[Test]
    public function job_experience_rejects_a_future_start_date(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->expertUser());

        // today is 1405-xx in Jalali; 1499 is comfortably in the future.
        $this->postJson('/api/profile/job-experiences', [
            'title' => 'X', 'start_date' => '1499-01-01', 'file' => $this->pdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    #[Test]
    public function job_experience_accepts_a_valid_past_date_and_null_end_date_for_ongoing_work(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->expertUser());

        // start in range, end_date omitted entirely → ongoing project.
        $this->postJson('/api/profile/job-experiences', [
            'title' => 'Ongoing site', 'start_date' => '1400-05-01', 'file' => $this->pdf(),
        ])->assertCreated();
    }

    // ─── #3 edit + guards ────────────────────────────────────────────────────────

    #[Test]
    public function a_user_can_edit_their_pending_job_experience(): void
    {
        $user = $this->expertUser();
        Sanctum::actingAs($user);

        $job = $user->jobExperiences()->create([
            JobExperience::TITLE => 'Old title',
            JobExperience::FILE_PATH => 'job_experiences/old.pdf',
            JobExperience::STATUS => JobExperience::STATUS_REJECTED,
        ]);

        $this->postJson("/api/profile/job-experiences/{$job->id}", ['title' => 'New title'])->assertOk();

        $job->refresh();
        $this->assertSame('New title', $job->title);
        // Any edit re-opens the record for admin review.
        $this->assertSame(JobExperience::STATUS_PENDING, $job->status);
    }

    #[Test]
    public function an_approved_job_experience_cannot_be_edited(): void
    {
        $user = $this->expertUser();
        Sanctum::actingAs($user);

        $job = $user->jobExperiences()->create([
            JobExperience::TITLE => 'Approved',
            JobExperience::FILE_PATH => 'job_experiences/a.pdf',
            JobExperience::STATUS => JobExperience::STATUS_APPROVED,
        ]);

        $this->postJson("/api/profile/job-experiences/{$job->id}", ['title' => 'Hack'])->assertStatus(400);
        $this->assertSame('Approved', $job->fresh()->title);
    }

    #[Test]
    public function a_user_cannot_edit_someone_elses_job_experience(): void
    {
        $owner = $this->expertUser();
        $stranger = $this->expertUser();
        Sanctum::actingAs($stranger);

        $job = $owner->jobExperiences()->create([
            JobExperience::TITLE => 'Mine',
            JobExperience::FILE_PATH => 'job_experiences/m.pdf',
            JobExperience::STATUS => JobExperience::STATUS_PENDING,
        ]);

        $this->postJson("/api/profile/job-experiences/{$job->id}", ['title' => 'Yours'])->assertForbidden();
        $this->assertSame('Mine', $job->fresh()->title);
    }

    // ─── #5 password verify step (non-consuming) ─────────────────────────────────

    #[Test]
    public function the_password_verify_step_checks_the_code_without_consuming_it(): void
    {
        $user = $this->expertUser();
        $user->forceFill([
            User::SECRET => \App\Support\OtpSecret::hash('123456'),
            User::SECRET_EXPIRES_AT => now()->addMinutes(5),
        ])->save();
        Sanctum::actingAs($user);

        // Wrong code is rejected.
        $this->postJson('/api/profile/password/verify', ['code' => '000000'])->assertStatus(400);

        // Correct code is accepted, and the secret is NOT consumed...
        $this->postJson('/api/profile/password/verify', ['code' => '123456'])->assertOk();
        $this->assertNotNull($user->fresh()->secret);

        // ...so the final step (set password) still succeeds with the same code.
        $this->postJson('/api/profile/password', [
            'code' => '123456',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        // Step 3 consumes the secret.
        $this->assertNull($user->fresh()->secret);
    }
}

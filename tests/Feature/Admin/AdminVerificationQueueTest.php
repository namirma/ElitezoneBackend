<?php

namespace Tests\Feature\Admin;

use App\Models\EduixRecord;
use App\Models\JobExperience;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the two verification queues that closed real operational gaps: the admin
 * can now see the queue of pending job-experience / education records (oldest-first),
 * open the document, and approve/reject with a reason.
 */
class AdminVerificationQueueTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $user;
    }

    private function jobExperience(string $status = JobExperience::STATUS_PENDING): JobExperience
    {
        return JobExperience::create([
            JobExperience::USER_ID => User::factory()->create()->id,
            JobExperience::TITLE => 'سرپرست کارگاه',
            JobExperience::ORGANIZATION_NAME => 'شرکت آزمون',
            JobExperience::FILE_PATH => 'job_experiences/'.uniqid().'.pdf',
            JobExperience::STATUS => $status,
        ]);
    }

    private function eduix(string $adminStatus = EduixRecord::STATUS_PENDING): EduixRecord
    {
        return EduixRecord::create([
            EduixRecord::USER_ID => User::factory()->create()->id,
            EduixRecord::NATIONAL_CODE => (string) random_int(1000000000, 9999999999),
            EduixRecord::TRACE_CODE => 'TR-'.uniqid(),
            EduixRecord::NAME => 'علی',
            EduixRecord::FAMILY => 'محمدی',
            EduixRecord::UNIVERSITY => 'دانشگاه تهران',
            EduixRecord::FIELD => 'عمران',
            EduixRecord::ADMIN_STATUS => $adminStatus,
        ]);
    }

    // ── Auth gate ─────────────────────────────────────────────────────────

    #[Test]
    public function guest_and_non_admin_cannot_reach_the_queues(): void
    {
        $this->getJson('/api/admin/job-experiences')->assertUnauthorized();
        $this->getJson('/api/admin/education-records')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/job-experiences')->assertForbidden();
        $this->getJson('/api/admin/education-records')->assertForbidden();
    }

    // ── Job experiences ───────────────────────────────────────────────────

    #[Test]
    public function admin_sees_pending_job_experiences_in_the_queue(): void
    {
        $pending = $this->jobExperience();
        $this->jobExperience(JobExperience::STATUS_APPROVED); // should NOT appear in default (pending) view

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/job-experiences')
            ->assertOk()
            ->assertJsonFragment(['id' => $pending->id])
            ->assertJsonPath('status', true);
    }

    #[Test]
    public function admin_can_download_a_job_experience_document(): void
    {
        Storage::fake('local');
        $je = $this->jobExperience();
        Storage::disk('local')->put($je->file_path, 'fake-pdf-bytes');

        Sanctum::actingAs($this->admin());
        $this->get("/api/admin/job-experiences/{$je->id}/file")->assertOk();
    }

    #[Test]
    public function admin_can_approve_a_job_experience(): void
    {
        $je = $this->jobExperience();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/job-experiences/{$je->id}/approve")->assertOk();
        $this->assertDatabaseHas('job_experiences', [
            'id' => $je->id, 'status' => JobExperience::STATUS_APPROVED,
        ]);
    }

    #[Test]
    public function rejecting_a_job_experience_requires_a_reason_and_stores_it(): void
    {
        $je = $this->jobExperience();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/job-experiences/{$je->id}/reject", [])->assertStatus(422);

        $this->postJson("/api/admin/job-experiences/{$je->id}/reject", ['rejection_reason' => 'مدرک ناخواناست'])
            ->assertOk();
        $this->assertDatabaseHas('job_experiences', [
            'id' => $je->id, 'status' => JobExperience::STATUS_REJECTED, 'rejection_reason' => 'مدرک ناخواناست',
        ]);
    }

    // ── Education (Eduix) ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_review_and_decide_education_records(): void
    {
        $record = $this->eduix();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/education-records')
            ->assertOk()
            ->assertJsonFragment(['id' => $record->id]);

        $this->postJson("/api/admin/education-records/{$record->id}/approve")->assertOk();
        $this->assertDatabaseHas('eduix_records', [
            'id' => $record->id, 'admin_status' => EduixRecord::STATUS_APPROVED,
        ]);

        $this->postJson("/api/admin/education-records/{$record->id}/reject", ['rejection_reason' => 'تاریخ نامعتبر'])
            ->assertOk();
        $this->assertDatabaseHas('eduix_records', [
            'id' => $record->id, 'admin_status' => EduixRecord::STATUS_REJECTED, 'rejection_reason' => 'تاریخ نامعتبر',
        ]);
    }
}

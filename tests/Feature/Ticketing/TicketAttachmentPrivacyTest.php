<?php

namespace Tests\Feature\Ticketing;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Models\Department;
use App\Models\TicketFile;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پیوست تیکت دادهٔ خصوصیِ کاربر است.
 *
 * پیش از این روی دیسک `public` ذخیره می‌شد و `TicketFileResource` یک URL مستقیم
 * برمی‌گرداند، پس فاکتور/کارت ملی/مدارک بانکیِ داخل تیکت با داشتنِ صرفِ لینک و
 * بدون لاگین قابل دریافت بود — در حالی که همین پروژه رسیدهای مالی را دقیقاً به
 * همین دلیل به دیسک خصوصی برده بود.
 */
class TicketAttachmentPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    private function department(): Department
    {
        // departments.slug پیش‌فرض DB ندارد — همان الگوی TicketLifecycleTest.
        return Department::create([
            'name' => 'مالی',
            'slug' => 'finance-'.uniqid(),
            'is_available_for_ticket' => true,
        ]);
    }

    private function ticketWithAttachment(User $owner): TicketFile
    {
        // TicketService::create مالک را از Auth::id() می‌گیرد، نه از DTO.
        Sanctum::actingAs($owner);

        $dto = new TicketDTO(
            department_id: $this->department()->id,
            subject: 'تست پیوست',
            message: 'متن',
            file: [UploadedFile::fake()->image('doc.png')],
        );

        $ticket = app(TicketService::class)->create($dto);

        return $ticket->files()->firstOrFail();
    }

    #[Test]
    public function attachments_are_written_to_the_private_disk_not_the_public_one(): void
    {
        Storage::fake(TicketFile::DISK);
        Storage::fake('public');

        $file = $this->ticketWithAttachment(User::factory()->create());
        $path = $file->{TicketFile::PATH};

        Storage::disk(TicketFile::DISK)->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('local', TicketFile::DISK, 'پیوست هرگز نباید روی دیسک public برود.');
    }

    #[Test]
    public function the_resource_exposes_a_protected_route_not_a_storage_url(): void
    {
        Storage::fake(TicketFile::DISK);

        $owner = User::factory()->create();
        $file = $this->ticketWithAttachment($owner);

        Sanctum::actingAs($owner);

        $url = $this->getJson('/api/ticket/'.$file->ticket->code)
            ->assertOk()
            ->json('data.attachments.0.url');

        $this->assertSame("/api/ticket/files/{$file->id}", $url);
        $this->assertStringNotContainsString('/storage/', (string) $url);
    }

    #[Test]
    public function a_stranger_cannot_download_someone_elses_attachment(): void
    {
        Storage::fake(TicketFile::DISK);

        $file = $this->ticketWithAttachment(User::factory()->create());

        Sanctum::actingAs(User::factory()->create());

        $this->get("/api/ticket/files/{$file->id}")->assertForbidden();
    }

    #[Test]
    public function a_guest_cannot_download_an_attachment(): void
    {
        Storage::fake(TicketFile::DISK);

        $file = $this->ticketWithAttachment(User::factory()->create());

        // ticketWithAttachment مالک را actingAs می‌کند — برای تستِ مهمان باید
        // گاردها پاک شوند وگرنه درخواست هنوز احراز هویت‌شده است.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/ticket/files/{$file->id}")->assertUnauthorized();
    }

    #[Test]
    public function the_owner_can_download_their_own_attachment(): void
    {
        Storage::fake(TicketFile::DISK);

        $owner = User::factory()->create();
        $file = $this->ticketWithAttachment($owner);

        Sanctum::actingAs($owner);

        $this->get("/api/ticket/files/{$file->id}")->assertOk();
    }

    #[Test]
    public function an_admin_can_download_any_attachment(): void
    {
        Storage::fake(TicketFile::DISK);

        $file = $this->ticketWithAttachment(User::factory()->create());

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        Sanctum::actingAs($admin);

        $this->get("/api/ticket/files/{$file->id}")->assertOk();
    }
}

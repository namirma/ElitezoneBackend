<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\SupplierActivityCategory;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * چرخه‌ی پذیرش: مدرک، دلیلِ رد، و بازگشت به صف.
 *
 * دو شکاف با هم بسته می‌شوند و مکملِ هم‌اند:
 *
 *   ۱. فرمِ درخواست فقط متن می‌گرفت — `standards` یک رشته‌ی آزاد بود که ادمین
 *      قرار بود «تأییدش» کند بدونِ هیچ مدرکی برای دیدن.
 *   ۲. ادمین می‌توانست رد کند ولی نمی‌توانست بگوید **چرا**؛ و حالا که ویرایشِ
 *      پروفایل متقاضی را به صف برمی‌گرداند، آن نیمه‌کاره‌بودن آزاردهنده‌تر هم
 *      بود: راهِ بازگشت باز شده و مقصد معلوم نیست.
 */
class SupplierOnboardingTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $applicant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->applicant = User::factory()->create();

        // نقشِ `supplier` باید وجود داشته باشد: `approve()` آن را با
        // `addRole('supplier')` وصل می‌کند و Laratrust روی نقشِ ناموجود
        // ModelNotFound می‌اندازد — که به ۴۰۴ ترجمه می‌شود و شبیهِ «روت نیست»
        // به‌نظر می‌رسد. در محیط واقعی سیدر می‌سازدش.
        Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    private function profile(): SupplierProfile
    {
        return SupplierProfile::create([SupplierProfile::USER_ID => $this->applicant->id]);
    }

    #[Test]
    public function an_applicant_without_the_role_can_still_attach_documents(): void
    {
        // ⚠️ نکته‌ی طراحی: این مسیر عمداً بیرونِ `role.supplier` است. مدرک را
        // متقاضی می‌فرستد — کسی که هنوز نقش ندارد و اتفاقاً همان کسی است که
        // ادمین برای تصمیم‌گیری به مدرکش نیاز دارد.
        $this->profile();

        Sanctum::actingAs($this->applicant);

        $this->assertFalse($this->applicant->hasRole('supplier'));

        $response = $this->postJson('/api/profile/supplier/documents', [
            'title' => 'پروانه‌ی کسب',
            'file' => UploadedFile::fake()->create('licence.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame('پروانه‌ی کسب', $response->json('data.title'));
        // مسیرِ دیسکِ خصوصی هرگز نباید به کلاینت برسد.
        $this->assertArrayNotHasKey('path', $response->json('data'));

        $document = SupplierDocument::firstOrFail();
        Storage::disk('local')->assertExists($document->{SupplierDocument::PATH});
    }

    #[Test]
    public function another_user_cannot_download_someone_elses_licence(): void
    {
        $this->profile();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier/documents', [
            'title' => 'کارت ملی',
            'file' => UploadedFile::fake()->create('id.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        $document = SupplierDocument::firstOrFail();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/profile/supplier/documents/{$document->id}/file")->assertStatus(403);

        // ولی ادمین — که صفِ پذیرش را بررسی می‌کند — باید بتواند ببیند.
        Sanctum::actingAs($this->admin);
        $this->get("/api/profile/supplier/documents/{$document->id}/file")->assertOk();
    }

    #[Test]
    public function deleting_a_document_removes_the_file_too(): void
    {
        $this->profile();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier/documents', [
            'title' => 'گواهی استاندارد',
            'file' => UploadedFile::fake()->create('cert.pdf', 30, 'application/pdf'),
        ])->assertCreated();

        $document = SupplierDocument::firstOrFail();
        $path = $document->{SupplierDocument::PATH};

        $this->deleteJson("/api/profile/supplier/documents/{$document->id}")->assertOk();

        // ردیفِ حذف‌شده با فایلِ باقی‌مانده یعنی مدرکِ هویتیِ یتیم روی دیسک.
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function an_executable_disguised_as_a_document_is_refused(): void
    {
        $this->profile();
        Sanctum::actingAs($this->applicant);

        $this->postJson('/api/profile/supplier/documents', [
            'title' => 'مدرک',
            'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertStatus(422);
    }

    #[Test]
    public function rejecting_without_a_reason_is_refused(): void
    {
        $this->profile();

        Sanctum::actingAs($this->admin);

        // «ثبتِ دلیل» باید واقعاً الزامی باشد، وگرنه متقاضی چیزی برای اصلاح ندارد.
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/reject", ['reason' => 'کوتاه'])
            ->assertStatus(422);
    }

    /**
     * ‏`GET /profile/supplier` روی برنچِ L13 وجود ندارد (فقط POST و DELETE) —
     * «تأمین‌کننده وضعیتِ خودش را می‌بیند» از فازهای بعدی است و هنوز پورت نشده.
     * skip به‌جای فورک‌کردنِ فایل، تا تست بین دو برنچ بایت‌به‌بایت یکی بماند.
     * نیمه‌ی دومِ این سناریو (ویرایش = درخواستِ دوباره) تستِ جداگانه‌ی خودش را
     * دارد، پس skip اینجا پوششی را از دست نمی‌دهد.
     */
    private function skipUnlessRouteExists(string $uri, string $method = 'GET'): void
    {
        $exists = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        if (! $exists) {
            $this->markTestSkipped("{$method} /{$uri} is not on this branch.");
        }
    }

    #[Test]
    public function the_applicant_can_read_why_they_were_rejected_and_re_apply(): void
    {
        $this->skipUnlessRouteExists('api/profile/supplier');

        $this->profile();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/reject", [
            'reason' => 'گواهی استاندارد پیوست نشده است.',
        ])->assertOk();

        Sanctum::actingAs($this->applicant);
        $data = $this->getJson('/api/profile/supplier')->assertOk()->json('data');

        $this->assertSame(SupplierProfile::STATUS_REJECTED, $data[SupplierProfile::STATUS]);
        $this->assertSame('گواهی استاندارد پیوست نشده است.', $data[SupplierProfile::REJECTION_REASON]);

        // ویرایشِ پروفایل = درخواستِ دوباره؛ دلیلِ قبلی باید برود، وگرنه ادمین
        // متنی را می‌بیند که دیگر صدق نمی‌کند.
        $category = SupplierActivityCategory::create(['name' => 'میلگرد '.uniqid()]);
        $this->postJson('/api/profile/supplier', [
            'company' => 'شرکت اصلاح‌شده',
            'activity_category_id' => [$category->id],
        ])->assertOk();

        $fresh = SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->firstOrFail();
        $this->assertSame(SupplierProfile::STATUS_PENDING, $fresh->{SupplierProfile::STATUS});
        $this->assertNull($fresh->{SupplierProfile::REJECTION_REASON});
    }

    #[Test]
    public function approving_clears_a_stale_rejection_reason(): void
    {
        $this->profile()->forceFill([
            SupplierProfile::STATUS => SupplierProfile::STATUS_REJECTED,
            SupplierProfile::REJECTION_REASON => 'دلیلِ قدیمی',
        ])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/approve")->assertOk();

        $fresh = SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->firstOrFail();
        $this->assertSame(SupplierProfile::STATUS_APPROVED, $fresh->{SupplierProfile::STATUS});
        $this->assertNull($fresh->{SupplierProfile::REJECTION_REASON});
    }
}

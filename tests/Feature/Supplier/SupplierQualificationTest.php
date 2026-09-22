<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Jobs\Notification\SendSmsJob;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSupplierQualification;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * چرخه‌ی کاملِ «سایت = دروازه‌ی صلاحیت، پنل = محلِ کار»
 * (`docs/specs/SUPPLIER_CONSOLIDATION_2026-09-19.md` §۴).
 *
 * draft (انتخابِ نقش) → pending (ارسالِ فرم) → needs_correction (ادمین) →
 * pending (ارسالِ دوباره) → approved (نقش + پیامکِ لینکِ پنل) — و در کنارش:
 * شخصِ حقوقی بدونِ آگهی تأسیس ارسال نمی‌شود، کدِ ملیِ غلط ۴۲۲ است، حسابِ
 * تأمین‌کننده خرید نمی‌کند، و ادمین مدرک را از مسیرِ خودش می‌گیرد.
 */
class SupplierQualificationTest extends TestCase
{
    use BuildsSupplierQualification, DatabaseTransactions, GrantsAdminPermissions;

    private User $applicant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);

        $this->applicant = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    private function draft(): SupplierProfile
    {
        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $this->applicant->id]);
        $profile->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_DRAFT])->save();

        return $profile;
    }

    private function profileStatus(): string
    {
        return (string) SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->value(SupplierProfile::STATUS);
    }

    #[Test]
    public function submitting_the_form_moves_a_draft_to_pending_and_stamps_submitted_at(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);

        $data = $this->postJson('/api/profile/supplier', $this->qualificationPayload())
            ->assertOk()->json('data.supplier');

        $this->assertSame(SupplierProfile::STATUS_PENDING, $data['status']);
        $this->assertNotNull($data['submitted_at']);
        $this->assertSame('individual', $data['entity_type']);
        $this->assertSame(self::VALID_NATIONAL_CODE, $data['representative_national_code']);
    }

    #[Test]
    public function a_company_cannot_submit_without_its_gazette_notice(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);

        $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'entity_type' => 'company',
            'national_id' => '10101234567',
            'registration_number' => '12345',
        ]))->assertStatus(422);

        $this->assertSame(SupplierProfile::STATUS_DRAFT, $this->profileStatus());

        // با آگهی تأسیس (kind=gazette) ارسال می‌شود.
        $this->postJson('/api/profile/supplier/documents', [
            'title' => 'آگهی تأسیس',
            'kind' => SupplierDocument::KIND_GAZETTE,
            'file' => UploadedFile::fake()->create('gazette.pdf', 40, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.kind', SupplierDocument::KIND_GAZETTE);

        $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'entity_type' => 'company',
            'national_id' => '10101234567',
            'registration_number' => '12345',
        ]))->assertOk();

        $this->assertSame(SupplierProfile::STATUS_PENDING, $this->profileStatus());
    }

    #[Test]
    public function the_form_is_validated_before_it_reaches_the_database(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);

        // شخصِ حقوقی بدونِ شناسه‌ی ملی و شماره‌ی ثبت؛ کدِ ملیِ نماینده با رقمِ کنترلِ غلط؛
        // کدپستیِ ۹ رقمی؛ شبای بی‌IR — همه ۴۲۲ و همه با کلیدِ فیلد.
        $response = $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'entity_type' => 'company',
            'representative_national_code' => '0499370898',
            'postal_code' => '815891381',
            'iban' => '123456789012345678901234',
        ]))->assertStatus(422);

        $errors = $response->json('errors') ?? $response->json('data') ?? [];
        foreach (['national_id', 'registration_number', 'representative_national_code', 'postal_code', 'iban'] as $field) {
            $this->assertArrayHasKey($field, $errors, "expected a validation error on {$field}");
        }
        $this->assertSame(SupplierProfile::STATUS_DRAFT, $this->profileStatus());
    }

    #[Test]
    public function persian_digits_are_accepted_in_numeric_fields(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);

        $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'postal_code' => '۸۱۵۸۹۱۳۸۱۱',
            'representative_national_code' => '۰۴۹۹۳۷۰۸۹۹',
        ]))->assertOk()->assertJsonPath('data.supplier.postal_code', '8158913811');
    }

    #[Test]
    public function request_correction_sends_the_reason_and_resubmitting_returns_to_the_queue(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->draft();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier', $this->qualificationPayload())->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/request-correction", ['reason' => 'کوتاه'])
            ->assertStatus(422);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/request-correction", [
            'reason' => 'کدپستی با نشانی نمی‌خواند؛ لطفاً اصلاح کنید.',
        ])->assertOk()->assertJsonPath('data.supplier_status', SupplierProfile::STATUS_NEEDS_CORRECTION);

        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) {
            $inner = (fn () => [$this->templateKey, $this->params])->call($job);

            return $inner[0] === 'supplier_needs_correction'
                && $inner[1]['reason'] === 'کدپستی با نشانی نمی‌خواند؛ لطفاً اصلاح کنید.';
        });

        Sanctum::actingAs($this->applicant);
        $data = $this->getJson('/api/profile/supplier')->assertOk()->json('data');
        $this->assertSame(SupplierProfile::STATUS_NEEDS_CORRECTION, $data['status']);
        $this->assertSame('کدپستی با نشانی نمی‌خواند؛ لطفاً اصلاح کنید.', $data['rejection_reason']);
        $this->assertFalse($this->applicant->fresh()->hasRole('supplier'));

        $this->postJson('/api/profile/supplier', $this->qualificationPayload(['postal_code' => '8158913812']))->assertOk();
        $this->assertSame(SupplierProfile::STATUS_PENDING, $this->profileStatus());
        $this->assertNull(SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->value(SupplierProfile::REJECTION_REASON));
    }

    #[Test]
    public function approval_attaches_the_role_and_texts_the_panel_link(): void
    {
        Bus::fake([SendSmsJob::class]);
        config(['services.supplier_panel.url' => 'https://panel.example.test']);
        $this->draft();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier', $this->qualificationPayload())->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/approve")->assertOk();

        $this->assertTrue($this->applicant->fresh()->hasRole('supplier'));
        $this->assertNotNull(SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->value(SupplierProfile::REVIEWED_AT));

        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) {
            $inner = (fn () => [$this->mobile, $this->templateKey, $this->params])->call($job);

            return $inner[0] === $this->applicant->{User::MOBILE}
                && $inner[1] === 'supplier_approved'
                && $inner[2]['panel_url'] === 'https://panel.example.test';
        });

        // تا ۱۴۰۵/۰۶/۳۱ این‌جا ۴۰۹ بود («تأییدشده نیاز به اصلاح نمی‌گیرد — مسیرش رد
        // است»). با قفلِ فیلدهای هویتی بعد از تأیید، همین اکشن کانالِ تغییرِ
        // مشروع شد: مجاز است و نقش می‌ماند (کسب‌وکار قطع نمی‌شود).
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/request-correction", [
            'reason' => 'نماینده‌ی قانونی عوض شده؛ لطفاً مشخصات را اصلاح کنید.',
        ])->assertOk()->assertJsonPath('data.supplier_status', SupplierProfile::STATUS_NEEDS_CORRECTION);
        $this->assertTrue($this->applicant->fresh()->hasRole('supplier'));
    }

    /** پروفایلِ تأییدشده با نقشِ وصل‌شده، همان‌طور که ادمین می‌سازد. */
    private function approvedProfile(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier', $this->qualificationPayload())->assertOk();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/approve")->assertOk();
        Sanctum::actingAs($this->applicant);
    }

    #[Test]
    public function an_approved_supplier_cannot_change_identity_fields_silently(): void
    {
        // قانون دو (علی ۱۴۰۵/۰۶/۳۱). تا آن روز این درخواست ۲۰۰ می‌داد و شناسه/کد ملی
        // را عوض می‌کرد در حالی که approved می‌ماند — زنده روی حسابِ علی سنجیده شد.
        Bus::fake([SendSmsJob::class]);
        $this->approvedProfile();

        $before = SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->first();

        $response = $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'representative_national_code' => '1234567891',
            'postal_code' => '8158913812',
        ]));
        $response->assertStatus(422)
            ->assertJsonPath('message', __('supplier.qualification.identity_locked'))
            ->assertJsonPath('errors.representative_national_code.0', __('supplier.qualification.identity_locked_field'))
            ->assertJsonPath('errors.postal_code.0', __('supplier.qualification.identity_locked_field'))
            ->assertJsonMissingPath('errors.company');

        $after = $before->fresh();
        $this->assertSame($before->{SupplierProfile::REPRESENTATIVE_NATIONAL_CODE}, $after->{SupplierProfile::REPRESENTATIVE_NATIONAL_CODE});
        $this->assertSame($before->{SupplierProfile::POSTAL_CODE}, $after->{SupplierProfile::POSTAL_CODE});
        $this->assertSame(SupplierProfile::STATUS_APPROVED, $after->{SupplierProfile::STATUS});

        // ردِ حسابرسی: پشتیبانی باید تلاش را ببیند.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => SupplierProfile::class,
            'subject_id' => $before->id,
            'causer_id' => $this->applicant->id,
            'description' => 'تلاش برای تغییر مشخصات هویتی تأمین‌کننده‌ی تأییدشده رد شد.',
        ]);
    }

    #[Test]
    public function an_approved_supplier_still_edits_the_free_fields_directly(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->approvedProfile();

        $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'brands' => 'آزمون‌فولاد',
            'website' => 'https://example.test',
        ]))->assertOk();

        $profile = SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->first();
        $this->assertSame('آزمون‌فولاد', $profile->{SupplierProfile::BRANDS});
        $this->assertSame(SupplierProfile::STATUS_APPROVED, $profile->{SupplierProfile::STATUS});
        $this->assertTrue($this->applicant->fresh()->hasRole('supplier'));
    }

    #[Test]
    public function the_admin_reopens_an_approved_profile_for_an_identity_change_and_the_role_survives(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->approvedProfile();

        // ۱. ادمین (بعد از تماسِ پشتیبانی) پرونده را باز می‌کند — با دلیل و پیامک.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/request-correction", [
            'reason' => 'نماینده‌ی قانونی طبق آگهی تغییرات عوض شده است.',
        ])->assertOk();
        $this->assertSame(SupplierProfile::STATUS_NEEDS_CORRECTION, $this->profileStatus());
        $this->assertTrue($this->applicant->fresh()->hasRole('supplier'), 'نقش نباید برود — پنل و تسویه ادامه دارد');

        // ۲. حالا همان تغییری که قفل بود، مجاز است و به صف برمی‌گردد.
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier', $this->qualificationPayload([
            'representative_national_code' => '1234567891',
        ]))->assertOk();
        $profile = SupplierProfile::where(SupplierProfile::USER_ID, $this->applicant->id)->first();
        $this->assertSame('1234567891', $profile->{SupplierProfile::REPRESENTATIVE_NATIONAL_CODE});
        $this->assertSame(SupplierProfile::STATUS_PENDING, $profile->{SupplierProfile::STATUS});

        // ۳. تأییدِ دوباره؛ نقش از قبل هست و دوباره وصل نمی‌شود.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/approve")->assertOk();
        $this->assertSame(SupplierProfile::STATUS_APPROVED, $this->profileStatus());
        $this->assertSame(1, $this->applicant->fresh()->roles()->where('name', 'supplier')->count());
    }

    #[Test]
    public function rejection_texts_the_reason(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->draft();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier', $this->qualificationPayload())->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/suppliers/{$this->applicant->id}/reject", ['reason' => 'مدارک با نام شرکت نمی‌خواند.'])->assertOk();

        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) {
            $inner = (fn () => [$this->templateKey, $this->params])->call($job);

            return $inner[0] === 'supplier_rejected' && $inner[1]['reason'] === 'مدارک با نام شرکت نمی‌خواند.';
        });
    }

    #[Test]
    public function the_admin_reads_documents_from_the_detail_and_downloads_them_from_the_admin_route(): void
    {
        $this->draft();
        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/supplier/documents', [
            'title' => 'فهرست محصولات',
            'kind' => SupplierDocument::KIND_PRODUCT_LIST,
            'file' => UploadedFile::fake()->create('products.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertCreated();
        $document = SupplierDocument::firstOrFail();

        Sanctum::actingAs($this->admin);
        $detail = $this->getJson("/api/admin/suppliers/{$this->applicant->id}")->assertOk()->json('data');
        $this->assertSame(SupplierDocument::KIND_PRODUCT_LIST, $detail['user']['supplier']['documents'][0]['kind']);

        $this->get("/api/admin/suppliers/{$this->applicant->id}/documents/{$document->id}/file")->assertOk();

        // مدرکِ کاربرِ دیگر از زیرِ این کاربر خوانده نمی‌شود.
        $other = User::factory()->create();
        SupplierProfile::create([SupplierProfile::USER_ID => $other->id]);
        $this->get("/api/admin/suppliers/{$other->id}/documents/{$document->id}/file")->assertStatus(404);
    }

    #[Test]
    public function drafts_stay_out_of_the_pending_queue(): void
    {
        $this->draft();

        Sanctum::actingAs($this->admin);
        $pending = $this->getJson('/api/admin/suppliers?status=pending')->assertOk()->json('data');
        $this->assertNotContains($this->applicant->id, array_column($pending, 'id'));

        $drafts = $this->getJson('/api/admin/suppliers?status=draft')->assertOk()->json('data');
        $this->assertContains($this->applicant->id, array_column($drafts, 'id'));
    }

    #[Test]
    public function picking_the_supplier_role_creates_a_draft_not_a_pending_request(): void
    {
        UserType::query()->firstOrCreate(['slug' => 'supplier'], ['name' => 'تأمین‌کننده', 'icon' => 'supplier']);
        UserType::query()->firstOrCreate(['slug' => 'employer'], ['name' => 'کارفرما', 'icon' => 'employer']);
        UserType::query()->firstOrCreate(['slug' => 'elite_yar'], ['name' => 'الیت‌یار', 'icon' => 'elite_yar']);

        Sanctum::actingAs($this->applicant);
        $this->postJson('/api/profile/type', ['roles' => ['supplier'], 'terms_accepted' => true])->assertOk();

        $this->assertSame(SupplierProfile::STATUS_DRAFT, $this->profileStatus());
    }

    #[Test]
    public function a_supplier_account_cannot_buy(): void
    {
        $this->draft();
        $type = UserType::query()->firstOrCreate(['slug' => 'supplier'], ['name' => 'تأمین‌کننده', 'icon' => 'supplier']);
        $this->applicant->forceFill(['type_id' => $type->id])->save();

        Sanctum::actingAs($this->applicant->fresh());
        $this->postJson('/api/cart/items', ['product_variant_id' => 1, 'quantity' => 1])
            ->assertStatus(403)
            ->assertJsonPath('message', __('auth.policy.supplier_cannot_buy'));
        $this->postJson('/api/checkout/pay', [])->assertStatus(403);
    }
}

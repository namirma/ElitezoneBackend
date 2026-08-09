<?php

namespace Tests\Feature\Profile;

use App\Models\Agreement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AgreementSeeder;
use Database\Seeders\UserTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * پذیرش الکترونیکی توافق‌نامه‌ها طبق «دستورالعمل پیاده‌سازی توافق‌نامه آنلاین الیت یار»:
 *  - GET /agreements/{slug} متن + چک‌باکس‌های CHK-01..08 را برمی‌گرداند
 *  - انتخاب نقش، سه سند (الیت‌زون + حریم خصوصی + اختصاصی نقش) را با نسخه/هش/snapshot ثبت می‌کند
 *  - سند الزامی بدون پذیرش → خطای BusinessException (400)
 *  - رسید پذیرش از GET /profile/agreements قابل بازیابی است
 */
class AgreementAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserTypeSeeder::class);
        $this->seed(AgreementSeeder::class);

        // نقش‌های Laratrust که SyncUserRolesAction متصل می‌کند
        foreach (['employer', 'supplier', 'engineer', 'contractor'] as $role) {
            Role::firstOrCreate(['name' => $role], ['display_name' => $role]);
        }
    }

    public function test_public_endpoint_returns_the_elite_yar_agreement_with_checkboxes(): void
    {
        $response = $this->getJson('/api/agreements/elite_yar_terms')->assertOk();

        $data = $response->json('data');
        $this->assertSame('elite_yar_terms', $data['slug']);
        $this->assertSame('1.0', $data['version']);
        $this->assertNotEmpty($data['content_sha256']);
        $this->assertStringContainsString('الیت یار', $data['content']);
        $this->assertCount(8, $data['checkboxes']);
        $this->assertSame('CHK-01', $data['checkboxes'][0]['code']);
    }

    public function test_unknown_agreement_slug_returns_404(): void
    {
        $this->getJson('/api/agreements/nonexistent_terms')->assertNotFound();
    }

    public function test_role_selection_with_aggregate_acceptance_records_all_three_agreements(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/type', [
            'roles' => ['employer'],
            'terms_accepted' => true,
        ])->assertOk();

        $slugs = $user->agreements()->pluck(Agreement::SLUG)->all();
        $this->assertContains(Agreement::SLUG_ELITEZONE_TERMS, $slugs);
        $this->assertContains(Agreement::SLUG_PRIVACY_POLICY, $slugs);
        $this->assertContains(Agreement::SLUG_EMPLOYER_TERMS, $slugs);
    }

    public function test_elite_yar_acceptance_via_checkboxes_records_version_hash_and_items(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $codes = ['CHK-01', 'CHK-02', 'CHK-03', 'CHK-04', 'CHK-05', 'CHK-06', 'CHK-07', 'CHK-08'];

        $this->postJson('/api/profile/type', [
            'roles' => ['elite_yar'],
            'terms_accepted' => true,
            'accepted_checkboxes' => $codes,
        ])->assertOk();

        $accepted = $user->agreements()
            ->where(Agreement::SLUG, Agreement::SLUG_ELITE_YAR_TERMS)
            ->first();

        $this->assertNotNull($accepted);
        $this->assertSame('1.0', $accepted->pivot->version);
        $this->assertSame($accepted->{Agreement::CONTENT_SHA256}, $accepted->pivot->content_sha256);

        $checkedItems = json_decode($accepted->pivot->checked_items, true);
        $this->assertCount(8, $checkedItems);
        $this->assertSame('CHK-01', $checkedItems[0]['code']);

        $snapshot = json_decode($accepted->pivot->snapshot, true);
        $this->assertSame('elite_yar_terms', $snapshot['agreement_type']);
        $this->assertSame($user->mobile, $snapshot['mobile_number']);
        $this->assertSame($codes, $snapshot['accepted_checkboxes']);
    }

    public function test_mandatory_agreement_without_acceptance_blocks_role_selection(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/type', [
            'roles' => ['employer'],
            // نه terms_accepted نه accepted_checkboxes
        ])->assertStatus(400);

        $this->assertSame(0, $user->agreements()->count());
    }

    public function test_acceptance_is_append_only_and_not_duplicated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/type', ['roles' => ['employer'], 'terms_accepted' => true])->assertOk();
        $firstAcceptedAt = $user->agreements()->first()->pivot->accepted_at;

        // انتخاب مجدد همان نقش — پذیرش قبلی نباید بازنویسی/تکرار شود
        $this->postJson('/api/profile/type', ['roles' => ['employer'], 'terms_accepted' => true])->assertOk();

        $this->assertSame(3, $user->agreements()->count());
        $this->assertEquals($firstAcceptedAt, $user->fresh()->agreements()->first()->pivot->accepted_at);
    }

    public function test_new_version_shows_in_pending_and_active_reacceptance_clears_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // پذیرش نسخه 1.0 با انتخاب نقش کارفرما
        $this->postJson('/api/profile/type', ['roles' => ['employer'], 'terms_accepted' => true])->assertOk();
        $this->getJson('/api/profile/agreements/pending')->assertOk()
            ->assertJsonPath('data.requires_acceptance', false);

        // انتشار نسخه‌ی مهم جدید — نسخه قبلی حذف نمی‌شود (الزام بند ۱۲ دستورالعمل)
        $v1 = Agreement::where(Agreement::SLUG, Agreement::SLUG_EMPLOYER_TERMS)->first();
        Agreement::create([
            Agreement::SLUG => Agreement::SLUG_EMPLOYER_TERMS,
            Agreement::TITLE => $v1->{Agreement::TITLE},
            Agreement::VERSION => '2.0',
            Agreement::CONTENT => $v1->{Agreement::CONTENT}."\n\n(اصلاحیه نسخه ۲)",
            Agreement::CONTENT_SHA256 => hash('sha256', $v1->{Agreement::CONTENT}.'v2'),
            Agreement::EFFECTIVE_AT => now(),
            Agreement::IS_ACTIVE => true,
            Agreement::IS_MANDATORY => true,
        ]);

        // نسخه جدید باید در فهرست pending بیاید
        $pending = $this->getJson('/api/profile/agreements/pending')->assertOk()->json('data');
        $this->assertTrue($pending['requires_acceptance']);
        $this->assertSame(Agreement::SLUG_EMPLOYER_TERMS, $pending['agreements'][0]['slug']);
        $this->assertSame('2.0', $pending['agreements'][0]['version']);

        // پذیرش فعال نسخه جدید → فهرست خالی می‌شود؛ پذیرش v1 دست‌نخورده می‌ماند
        $this->postJson('/api/profile/agreements/employer_terms/accept', ['terms_accepted' => true])
            ->assertOk()
            ->assertJsonPath('data.version', '2.0');

        $this->getJson('/api/profile/agreements/pending')->assertOk()
            ->assertJsonPath('data.requires_acceptance', false);
        $this->assertSame(
            2,
            $user->agreements()->where(Agreement::SLUG, Agreement::SLUG_EMPLOYER_TERMS)->count()
        );
    }

    public function test_accepting_an_agreement_not_relevant_to_the_user_role_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // کاربر نقش سوپلایر ندارد — سند سوپلایر برایش قابل پذیرش نیست
        $this->postJson('/api/profile/agreements/supplier_terms/accept', ['terms_accepted' => true])
            ->assertStatus(422);
    }

    public function test_accepted_version_pdf_is_served_and_unaccepted_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // قبل از هر پذیرشی → 404 (بند ۹: PDF پنل فقط نسخه‌ی پذیرفته‌شده است)
        $this->getJson('/api/profile/agreements/employer_terms/pdf')->assertNotFound();

        $this->postJson('/api/profile/type', ['roles' => ['employer'], 'terms_accepted' => true])->assertOk();

        $this->get('/api/profile/agreements/employer_terms/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_public_pdf_of_the_latest_active_version_is_served(): void
    {
        $this->get('/api/agreements/elite_yar_terms/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_my_acceptances_receipt_endpoint(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/type', ['roles' => ['employer'], 'terms_accepted' => true])->assertOk();

        $response = $this->getJson('/api/profile/agreements')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->pluck('slug')->contains(Agreement::SLUG_EMPLOYER_TERMS));
        $this->assertSame('1.0', $rows->first()['accepted_version']);
        $this->assertNotNull($rows->first()['accepted_at']);
    }
}

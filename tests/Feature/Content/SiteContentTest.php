<?php

namespace Tests\Feature\Content;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * قراردادِ دو ماژولِ محتوایی که فرانتِ اصلی مستقیماً مصرفشان می‌کند:
 * دسته‌بندی سوالات متداول (کشوی پشتیبانی) و منوی سایت (مگامنو + فوتر).
 *
 * این تست‌ها همان چیزهایی را قفل می‌کنند که اگر بشکنند، سایت بی‌صدا خراب
 * می‌شود: نمایشِ درختی، جداییِ header/footer، و گاردهای حذف/عمق/حلقه.
 */
class SiteContentTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    // ── FAQ categories ────────────────────────────────────────────────────

    #[Test]
    public function public_faq_categories_returns_active_categories_with_their_active_faqs(): void
    {
        $category = FaqCategory::create([
            FaqCategory::SLUG => 'test-cat-'.uniqid(),
            FaqCategory::TITLE => 'دسته تست',
            FaqCategory::SUBTITLE => 'زیرعنوان',
            FaqCategory::SORT_ORDER => 0,
        ]);

        Faq::create([
            Faq::FAQ_CATEGORY_ID => $category->getKey(),
            Faq::QUESTION => 'سوال فعال؟',
            Faq::ANSWER => 'پاسخ',
            Faq::IS_ACTIVE => true,
        ]);
        Faq::create([
            Faq::FAQ_CATEGORY_ID => $category->getKey(),
            Faq::QUESTION => 'سوال غیرفعال؟',
            Faq::ANSWER => 'پاسخ',
            Faq::IS_ACTIVE => false,
        ]);

        $response = $this->getJson('/api/faq-categories')->assertOk();

        $payload = collect($response->json('data'))
            ->firstWhere('slug', $category->{FaqCategory::SLUG});

        $this->assertNotNull($payload, 'دسته‌ی فعال باید در خروجی عمومی باشد');
        $this->assertSame('زیرعنوان', $payload['subtitle']);
        $this->assertCount(1, $payload['faqs'], 'فقط سوالات فعال باید برگردند');
        $this->assertSame('سوال فعال؟', $payload['faqs'][0]['question']);
    }

    #[Test]
    public function an_inactive_faq_category_is_hidden_from_the_public_endpoint(): void
    {
        $category = FaqCategory::create([
            FaqCategory::SLUG => 'hidden-'.uniqid(),
            FaqCategory::TITLE => 'مخفی',
            FaqCategory::IS_ACTIVE => false,
        ]);

        $slugs = collect($this->getJson('/api/faq-categories')->assertOk()->json('data'))
            ->pluck('slug');

        $this->assertNotContains($category->{FaqCategory::SLUG}, $slugs);
    }

    #[Test]
    public function deleting_a_faq_category_that_still_has_questions_is_blocked(): void
    {
        Sanctum::actingAs($this->admin());

        $category = FaqCategory::create([
            FaqCategory::SLUG => 'busy-'.uniqid(),
            FaqCategory::TITLE => 'پر',
        ]);
        Faq::create([
            Faq::FAQ_CATEGORY_ID => $category->getKey(),
            Faq::QUESTION => 'س؟',
            Faq::ANSWER => 'پ',
        ]);

        $this->deleteJson("/api/admin/faq-categories/{$category->getKey()}")
            ->assertStatus(422);

        $this->assertNotNull(FaqCategory::find($category->getKey()));
    }

    // ── Menu items ────────────────────────────────────────────────────────

    #[Test]
    public function the_public_menu_is_scoped_by_location(): void
    {
        $header = MenuItem::create([
            MenuItem::LOCATION => MenuItem::LOCATION_HEADER,
            MenuItem::TITLE => 'آیتم نوار بالا '.uniqid(),
            MenuItem::URL => '/rebar',
        ]);
        $footer = MenuItem::create([
            MenuItem::LOCATION => MenuItem::LOCATION_FOOTER,
            MenuItem::TITLE => 'ستون فوتر '.uniqid(),
        ]);

        $headerTitles = collect($this->getJson('/api/menu?location=header')->assertOk()->json('data'))
            ->pluck('title');
        $footerTitles = collect($this->getJson('/api/menu?location=footer')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertContains($header->{MenuItem::TITLE}, $headerTitles);
        $this->assertNotContains($footer->{MenuItem::TITLE}, $headerTitles);

        $this->assertContains($footer->{MenuItem::TITLE}, $footerTitles);
        $this->assertNotContains($header->{MenuItem::TITLE}, $footerTitles);
    }

    #[Test]
    public function an_unknown_location_falls_back_to_header_instead_of_returning_nothing(): void
    {
        $header = MenuItem::create([
            MenuItem::LOCATION => MenuItem::LOCATION_HEADER,
            MenuItem::TITLE => 'فالبک '.uniqid(),
        ]);

        $titles = collect($this->getJson('/api/menu?location=sidebar')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertContains($header->{MenuItem::TITLE}, $titles);
    }

    #[Test]
    public function the_public_menu_nests_children_and_hides_inactive_branches(): void
    {
        $root = MenuItem::create([
            MenuItem::LOCATION => MenuItem::LOCATION_HEADER,
            MenuItem::TITLE => 'ریشه '.uniqid(),
        ]);
        MenuItem::create([
            MenuItem::PARENT_ID => $root->getKey(),
            MenuItem::LOCATION => MenuItem::LOCATION_HEADER,
            MenuItem::TITLE => 'فرزند فعال',
            MenuItem::SORT_ORDER => 0,
        ]);
        MenuItem::create([
            MenuItem::PARENT_ID => $root->getKey(),
            MenuItem::LOCATION => MenuItem::LOCATION_HEADER,
            MenuItem::TITLE => 'فرزند غیرفعال',
            MenuItem::IS_ACTIVE => false,
            MenuItem::SORT_ORDER => 1,
        ]);

        $payload = collect($this->getJson('/api/menu?location=header')->assertOk()->json('data'))
            ->firstWhere('id', $root->getKey());

        $this->assertNotNull($payload);
        $this->assertCount(1, $payload['children']);
        $this->assertSame('فرزند فعال', $payload['children'][0]['title']);
    }

    #[Test]
    public function a_menu_item_cannot_be_nested_deeper_than_three_levels(): void
    {
        Sanctum::actingAs($this->admin());

        $level1 = MenuItem::create([MenuItem::TITLE => 'سطح ۱ '.uniqid()]);
        $level2 = MenuItem::create([MenuItem::PARENT_ID => $level1->getKey(), MenuItem::TITLE => 'سطح ۲']);
        $level3 = MenuItem::create([MenuItem::PARENT_ID => $level2->getKey(), MenuItem::TITLE => 'سطح ۳']);

        $this->postJson('/api/admin/menu-items', [
            MenuItem::PARENT_ID => $level3->getKey(),
            MenuItem::TITLE => 'سطح ۴ — نباید ساخته شود',
        ])->assertStatus(422)->assertJsonValidationErrors(MenuItem::PARENT_ID);
    }

    #[Test]
    public function a_menu_item_cannot_become_its_own_descendant(): void
    {
        Sanctum::actingAs($this->admin());

        $parent = MenuItem::create([MenuItem::TITLE => 'والد '.uniqid()]);
        $child = MenuItem::create([MenuItem::PARENT_ID => $parent->getKey(), MenuItem::TITLE => 'فرزند']);

        // والدِ خودش
        $this->putJson("/api/admin/menu-items/{$parent->getKey()}", [
            MenuItem::PARENT_ID => $parent->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors(MenuItem::PARENT_ID);

        // زیرمجموعه‌ی فرزندِ خودش (حلقه)
        $this->putJson("/api/admin/menu-items/{$parent->getKey()}", [
            MenuItem::PARENT_ID => $child->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors(MenuItem::PARENT_ID);
    }

    #[Test]
    public function a_menu_item_with_children_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $parent = MenuItem::create([MenuItem::TITLE => 'والدِ حذف‌ناپذیر '.uniqid()]);
        MenuItem::create([MenuItem::PARENT_ID => $parent->getKey(), MenuItem::TITLE => 'فرزند']);

        $this->deleteJson("/api/admin/menu-items/{$parent->getKey()}")->assertStatus(422);

        $this->assertNotNull(MenuItem::find($parent->getKey()));
    }

    #[Test]
    public function a_menu_url_must_be_an_internal_path_or_an_absolute_url(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/menu-items', [
            MenuItem::TITLE => 'لینک نامعتبر',
            MenuItem::URL => 'rebar',
        ])->assertStatus(422)->assertJsonValidationErrors(MenuItem::URL);

        $this->postJson('/api/admin/menu-items', [
            MenuItem::TITLE => 'لینک داخلی '.uniqid(),
            MenuItem::URL => '/rebar',
        ])->assertStatus(201);
    }

    #[Test]
    public function saving_a_menu_item_flushes_the_public_menu_cache(): void
    {
        Sanctum::actingAs($this->admin());

        // کش را با یک درخواست عمومی گرم می‌کنیم
        $this->getJson('/api/menu?location=header')->assertOk();
        $this->assertTrue(Cache::has(MenuItem::cacheKeyFor(MenuItem::LOCATION_HEADER)));

        MenuItem::create([MenuItem::TITLE => 'آیتم تازه '.uniqid()]);

        $this->assertFalse(
            Cache::has(MenuItem::cacheKeyFor(MenuItem::LOCATION_HEADER)),
            'ذخیره‌ی آیتم منو باید کشِ همان جایگاه را پاک کند، وگرنه ادمین تغییرش را در سایت نمی‌بیند'
        );
    }
}

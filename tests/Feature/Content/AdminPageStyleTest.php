<?php

namespace Tests\Feature\Content;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * استایلِ **سطحِ صفحه** — فازِ ۳ (۲۰۲۶-۰۹-۱۲).
 *
 * این فایل به یک دلیلِ مشخص وجود دارد: اولین نسخه‌ی `UpdatePageRequest` روی
 * صفحه‌ی بلوکی هم‌زمان `prohibited` و `required` را به `slug` می‌داد — یک شرطِ
 * ارضاناپذیر که **هر** ویرایشی روی آن صفحه را ۴۲۲ می‌کرد. بیلد سبز بود، لینت
 * سبز، کنسول تمیز، و ۱۲۱۸ تستِ دیگر هم سبز؛ فقط یک PUTِ واقعی از مرورگر
 * نشانش داد. تستِ زیر همان PUT است.
 */
class AdminPageStyleTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($this->admin);

        Sanctum::actingAs($this->admin);
    }

    private function homePage(): Page
    {
        return Page::where(Page::SLUG, PageBlock::PAGE_HOME)->firstOrFail();
    }

    #[Test]
    public function style_alone_is_a_valid_update_of_a_block_page(): void
    {
        $page = $this->homePage();

        $this->putJson("/api/admin/pages/{$page->id}", [
            'style' => ['blockGap' => '24', 'pagePadTop' => '40'],
        ])->assertOk();

        $this->assertSame(
            ['blockGap' => '24', 'pagePadTop' => '40'],
            $page->fresh()->{Page::STYLE}
        );
    }

    #[Test]
    public function a_style_only_update_leaves_title_and_body_alone(): void
    {
        // `body` روی صفحه‌ی بلوکی NULL است و `title` باید دست‌نخورده بماند.
        // اگر قواعد به `required` برگردند، این دو یا پاک می‌شوند یا ۴۲۲.
        $page = $this->homePage();
        $title = $page->{Page::TITLE};

        $this->putJson("/api/admin/pages/{$page->id}", ['style' => ['blockGap' => '8']])->assertOk();

        $this->assertSame($title, $page->fresh()->{Page::TITLE});
    }

    #[Test]
    public function the_slug_of_a_block_page_cannot_be_changed(): void
    {
        // `page_blocks.page` یک رشته است نه کلیدِ خارجی، پس تغییرِ اسلاگ هر
        // بلوکِ آن صفحه را بی‌صدا یتیم می‌کند: صفحه خالی، بدونِ هیچ خطایی.
        $page = $this->homePage();

        $this->putJson("/api/admin/pages/{$page->id}", ['slug' => 'home-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertSame(PageBlock::PAGE_HOME, $page->fresh()->{Page::SLUG});
    }

    #[Test]
    public function a_value_outside_the_geometry_ladder_is_rejected(): void
    {
        // نردبان همان است که پنل در `Select` می‌گذارد. اگر این دو واگرا شوند،
        // ادمین مقداری را انتخاب می‌کند که ۴۲۲ می‌گیرد — همان شکستی که
        // `scripts/style-slot-mirror.mjs` برای گرفتنش ساخته شد.
        $page = $this->homePage();

        $this->putJson("/api/admin/pages/{$page->id}", ['style' => ['blockGap' => '17']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('style.blockGap');
    }

    #[Test]
    public function an_unknown_page_level_slot_is_rejected(): void
    {
        // اسلاتِ بلوک (`titleSize`) در دامنه‌ی صفحه معنا ندارد و نباید بی‌صدا
        // ذخیره شود — یک کلیدِ ذخیره‌شده‌ی بی‌مصرف همان «کنترلِ تزئینی» است.
        $page = $this->homePage();

        $this->putJson("/api/admin/pages/{$page->id}", ['style' => ['titleSize' => '48']])
            ->assertOk();

        $this->assertArrayNotHasKey('titleSize', $page->fresh()->{Page::STYLE} ?? []);
    }

    #[Test]
    public function saving_a_style_flushes_the_public_page_cache(): void
    {
        $page = $this->homePage();

        // کش را با یک درخواستِ عمومی گرم کن…
        $this->getJson('/api/pages/home/blocks')->assertOk();

        $this->putJson("/api/admin/pages/{$page->id}", ['style' => ['blockGap' => '64']])->assertOk();

        // …و بدونِ هیچ پاک‌کردنِ دستی، مقدارِ تازه باید بیاید. بدونِ فلاش،
        // ادمین ذخیره می‌کند، ۲۰۰ می‌گیرد، و تا یک ساعت هیچ اتفاقی نمی‌افتد.
        $this->assertSame(
            ['blockGap' => '64'],
            $this->getJson('/api/pages/home/blocks')->assertOk()->json('data.style')
        );
    }

    #[Test]
    public function the_schema_lists_the_pages_and_their_style_slots(): void
    {
        Cache::forget(Page::BLOCK_PAGES_CACHE_KEY);

        $data = $this->getJson('/api/admin/page-blocks/schema')->assertOk()->json('data');

        $this->assertArrayHasKey('pages', $data);
        $this->assertArrayHasKey('page_style_slots', $data);

        $bySlug = collect($data['pages'])->keyBy('slug');

        // مسیر **همان اسلاگ نیست** — تا فازِ ۳ این دانش فقط در `PreviewPane.jsx`
        // بک‌آفیس بود.
        $this->assertSame('/steel-kavirCo', $bySlug[PageBlock::PAGE_STEEL_KAVIR]['route']);
        $this->assertSame('/', $bySlug[PageBlock::PAGE_HOME]['route']);

        $this->assertSame(
            ['blockGap', 'pagePadTop', 'pagePadBottom'],
            array_keys($data['page_style_slots'])
        );
    }
}

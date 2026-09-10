<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use App\Models\User;
use App\Services\Content\PageBlockSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class AdminPageBlockTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    #[Test]
    public function a_guest_cannot_list_blocks(): void
    {
        $this->getJson('/api/admin/page-blocks?page=home')->assertUnauthorized();
    }

    #[Test]
    public function an_admin_creates_a_block(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home',
            'type' => 'story_card',
            'sort_order' => 1,
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ])->assertCreated();

        $this->assertDatabaseHas(PageBlock::TABLE, [PageBlock::TYPE => 'story_card']);
    }

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'not_a_block', 'data' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    /**
     * نگهبانِ رگرسیون: `(string) $this->input('type')` روی یک آرایه هشدارِ PHP
     * می‌دهد که هندلرِ لاراول به استثنا تبدیلش می‌کند — ۵۰۰ به‌جای ۴۲۲ برای یک
     * ورودیِ بدشکل که باید فقط رد شود.
     */
    #[Test]
    public function a_non_string_type_on_store_is_a_422_not_a_500(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => [], 'data' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    #[Test]
    public function a_non_string_type_on_update_is_a_422_not_a_500(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'type' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    #[Test]
    public function a_required_field_from_the_schema_is_enforced(): void
    {
        Sanctum::actingAs($this->admin);

        // story_card می‌گوید `title` الزامی است — قاعده از رجیستری می‌آید،
        // نه از فهرستی که دستی در FormRequest نوشته شده باشد.
        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'story_card',
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.title');
    }

    #[Test]
    public function the_schema_endpoint_lists_every_registered_type(): void
    {
        Sanctum::actingAs($this->admin);

        $body = $this->getJson('/api/admin/page-blocks/schema')->assertOk()->json('data');
        $types = array_keys($body['types']);

        $this->assertContains('story_card', $types);
        $this->assertContains('card_grid', $types);
    }

    /**
     * نگهبانِ رگرسیون برای همان شکافی که این تست را عوض کرد: یک فیلدِ `list`
     * می‌تواند `of => 'card'` بگوید، ولی تا پیش از این `card` هیچ‌جای پاسخ
     * تعریف نمی‌شد — بک‌آفیس نامِ شکل را می‌دید و نمی‌توانست زیرفرمش را بسازد.
     * این تست هر هفده نوع را می‌گردد، هر `of` را که واقعاً کلیدی در
     * `PageBlockSchema::SHAPES` است (همان تشخیصی که `listRules()` خودش برای
     * «فهرستِ شکل‌دار در برابرِ فهرستِ اسکالر» به کار می‌برد) جمع می‌کند، و
     * مطمئن می‌شود همان کلید در `data.shapes` هم سرو می‌شود.
     */
    #[Test]
    public function every_shaped_list_reference_resolves_to_a_shape_the_panel_can_see(): void
    {
        Sanctum::actingAs($this->admin);

        $body = $this->getJson('/api/admin/page-blocks/schema')->assertOk()->json('data');
        $shapeKeysInResponse = array_keys($body['shapes']);

        foreach ($body['types'] as $typeName => $definition) {
            foreach ($definition['fields'] as $fieldName => $field) {
                if (($field['type'] ?? null) !== 'list' || ! isset($field['of'])) {
                    continue;
                }

                $of = $field['of'];

                if (! array_key_exists($of, PageBlockSchema::SHAPES)) {
                    // اسکالر است (مثلِ `textarea`/`number`) — شکل نمی‌خواهد. ولی
                    // «شکل نیست» به‌تنهایی کافی نیست: یک `of` غلط‌املایی هم شکل
                    // نیست و بدونِ این ادعا بی‌صدا از تست رد می‌شد، در حالی که
                    // بک‌آفیس نه شکلی برایش پیدا می‌کند نه ویجتی.
                    $this->assertContains(
                        $of,
                        PageBlockSchema::WIDGETS,
                        "«{$typeName}.{$fieldName}» با «of => {$of}» به چیزی اشاره می‌کند که نه شکلِ تعریف‌شده است نه نامِ ویجت."
                    );

                    continue;
                }

                $this->assertContains(
                    $of,
                    $shapeKeysInResponse,
                    "«{$typeName}.{$fieldName}» با «of => {$of}» به شکلی اشاره می‌کند که پاسخِ اسکیما سرو نمی‌کند."
                );
            }
        }
    }

    /**
     * نگهبانِ رگرسیون: `UpdatePageBlockRequest` وقتی `type` در بدنه نیست، آن را
     * از نوعِ ذخیره‌شده روی ردیف می‌گیرد. اگر آن fallback بشکند (مثلاً به دلیلِ
     * ناهم‌نامیِ پارامترِ روت — همان باگِ «route-parameter gotcha» که قبلاً در
     * `UpdateMenuItemRequest` رخ داد)، `PageBlockSchema::rulesFor()` رشته‌ی
     * خالی می‌گیرد، آرایه‌ی خالی برمی‌گرداند، و `data` بدونِ هیچ اعتبارسنجی‌ای
     * مستقیم روی یک صفحه‌ی عمومی می‌نشیند. این تست دقیقاً همان مسیر را می‌بندد.
     */
    #[Test]
    public function updating_without_a_type_still_validates_against_the_stored_type(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        // `title` را حذف می‌کنیم و `type` را اصلاً نمی‌فرستیم — قاعده باید از
        // نوعِ ذخیره‌شده (story_card) بیاید، نه از بدنه‌ی درخواست.
        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.title');
    }

    #[Test]
    public function an_admin_updates_a_block(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوانِ قدیم'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوانِ جدید'],
        ])->assertOk();

        $this->assertSame('عنوانِ جدید', $block->fresh()->{PageBlock::DATA}['title']);
    }

    /**
     * نگهبانِ رگرسیون: `data` روی این ریکوئست «sometimes» است، اما قواعدِ
     * ادغام‌شده از اسکیما implicit-required بودند — یعنی حذف‌کردنِ `data` از
     * بدنه هنوز روی `data.title` شکست می‌خورد. یک آپدیتِ جزئی که فقط
     * `sort_order` را عوض می‌کند باید موفق شود و `data` را دست‌نخورده بگذارد.
     */
    #[Test]
    public function omitting_data_on_update_leaves_it_untouched_and_still_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'sort_order' => 3,
        ])->assertOk();

        $fresh = $block->fresh();
        $this->assertSame(3, $fresh->{PageBlock::SORT_ORDER});
        $this->assertSame('عنوان', $fresh->{PageBlock::DATA}['title']);
    }

    #[Test]
    public function sending_data_incomplete_on_update_still_422s(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'data' => ['image' => 'blocks/a.webp'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.alt');
    }

    /**
     * نگهبانِ رگرسیون: پیش از این `data.glass` بدونِ قاعده‌ی فرزند بود، پس
     * لاراول نمی‌توانست کلیدِ ناشناخته را از آرایه حذف کند و هر JSONای که
     * ادمین می‌فرستاد همان‌طور ذخیره می‌شد.
     */
    #[Test]
    public function an_unknown_glass_key_is_stripped_from_what_gets_stored(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'video',
            'data' => [
                'videoSrc' => 'https://cdn.example.com/v.mp4',
                'glass' => ['variant' => 'clear', 'evil' => '<script>alert(1)</script>'],
            ],
        ])->assertCreated();

        $stored = PageBlock::latest('id')->first()->{PageBlock::DATA};
        $this->assertSame('clear', $stored['glass']['variant']);
        $this->assertArrayNotHasKey('evil', $stored['glass']);
    }

    #[Test]
    public function an_unknown_glass_variant_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'video',
            'data' => [
                'videoSrc' => 'https://cdn.example.com/v.mp4',
                'glass' => ['variant' => 'not-a-real-variant'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('data.glass.variant');
    }

    /**
     * نگهبانِ رگرسیون: `link` سطحِ بالا همیشه `.to` را به ۲۰۴۸ کاراکتر محدود
     * کرده؛ همین شکل وقتی عضوِ یک SHAPE بود (این‌جا `audience.link` زیرِ
     * `glass_cards.cards`) هیچ سقفی نداشت.
     */
    #[Test]
    public function a_shape_level_link_too_long_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'glass_cards',
            'data' => [
                'cards' => [[
                    'title' => 'ت', 'sub' => 'ز', 'body' => 'ب', 'cta' => 'ک',
                    'link' => ['to' => str_repeat('a', 2049), 'label' => 'x'],
                ]],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('data.cards.0.link.to');
    }

    #[Test]
    public function updating_with_an_unknown_type_in_the_body_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'type' => 'not_a_block',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    #[Test]
    public function reorder_only_touches_the_rows_it_is_given(): void
    {
        Sanctum::actingAs($this->admin);

        $a = PageBlock::create([PageBlock::PAGE => 'home', PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 1, PageBlock::DATA => []]);
        $b = PageBlock::create([PageBlock::PAGE => 'home', PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 2, PageBlock::DATA => []]);
        $untouched = PageBlock::create([PageBlock::PAGE => 'home', PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 9, PageBlock::DATA => []]);

        $this->postJson('/api/admin/page-blocks/reorder', [
            'items' => [['id' => $a->id, 'sort_order' => 2], ['id' => $b->id, 'sort_order' => 1]],
        ])->assertOk();

        $this->assertSame(2, $a->fresh()->{PageBlock::SORT_ORDER});
        $this->assertSame(1, $b->fresh()->{PageBlock::SORT_ORDER});
        $this->assertSame(9, $untouched->fresh()->{PageBlock::SORT_ORDER});
    }

    /**
     * نگهبانِ رگرسیون: `exists:page_blocks,id` یک کوئریِ خامِ دیتابیس است و
     * اسکوپِ SoftDeletes مدل را نمی‌بیند، پس ردیفِ حذف‌شده هم اعتبارسنجی را رد
     * می‌کرد — و بعد `whereKey()->update()` (که همان اسکوپ را *دارد*) بی‌صدا
     * هیچ ردیفی را عوض نمی‌کرد: یک ۲۰۰ که کاری نکرده.
     */
    #[Test]
    public function reorder_rejects_a_soft_deleted_id_instead_of_silently_doing_nothing(): void
    {
        Sanctum::actingAs($this->admin);

        $a = PageBlock::create([PageBlock::PAGE => 'home', PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 1, PageBlock::DATA => []]);
        $a->delete();

        $this->postJson('/api/admin/page-blocks/reorder', [
            'items' => [['id' => $a->id, 'sort_order' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.id');
    }

    /**
     * نگهبانِ رگرسیون برای بندِ فلاشِ کش در `reorder()`.
     *
     * `update()` روی کوئری‌بیلدر رویدادِ مدل نمی‌زند، پس `PageBlock::booted()`
     * هرگز اجرا نمی‌شود و کشِ صفحه‌ی عمومی تا یک ساعت با ترتیبِ قدیمی می‌ماند.
     * این تست دقیقاً همان مسیرِ کاربر را طی می‌کند: اول صفحه‌ی عمومی را کش
     * می‌کند، بعد ترتیب را عوض می‌کند، و دوباره صفحه‌ی عمومی را می‌خواند — اگر
     * فلاشِ صریح در `reorder()` حذف شود این تست باید شکست بخورد.
     */
    #[Test]
    public function reorder_flushes_the_public_page_cache(): void
    {
        Sanctum::actingAs($this->admin);

        $a = PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_HOME, PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 1, PageBlock::DATA => []]);
        $b = PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_HOME, PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 2, PageBlock::DATA => []]);

        $before = $this->getJson('/api/pages/home/blocks')->assertOk()->json('data');
        $this->assertSame($a->id, $before[0]['id']);
        $this->assertSame($b->id, $before[1]['id']);

        $this->postJson('/api/admin/page-blocks/reorder', [
            'items' => [['id' => $a->id, 'sort_order' => 2], ['id' => $b->id, 'sort_order' => 1]],
        ])->assertOk();

        $after = $this->getJson('/api/pages/home/blocks')->assertOk()->json('data');
        $this->assertSame($b->id, $after[0]['id']);
        $this->assertSame($a->id, $after[1]['id']);
    }

    #[Test]
    public function an_svg_is_refused_by_the_media_endpoint(): void
    {
        Sanctum::actingAs($this->admin);

        // دیسکِ public از مبدأِ خودِ اپ سرو می‌شود، پس SVGِ حاوی <script>
        // یعنی XSSِ ذخیره‌شده و `script-src 'self'` جلویش را نمی‌گیرد.
        $this->postJson('/api/admin/page-blocks/media', [
            'file' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[Test]
    public function a_png_is_stored_and_its_url_returned(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $body = $this->postJson('/api/admin/page-blocks/media', [
            'file' => UploadedFile::fake()->image('x.png'),
        ])->assertOk()->json('data');

        Storage::disk('public')->assertExists($body['path']);
        $this->assertNotEmpty($body['url']);
    }
}

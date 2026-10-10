<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Database\Seeders\PageBlocksSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ریدیزاین v276 (طرحِ رضا شهریار) — شکل‌های تازه‌ی بلوک‌های صفحه‌ساز برای هوم، فولاد کویر و ویپاد.
 *
 * هر فیلدِ تازه افزودنی است: ردیفی که آن را ندارد همان رفتارِ قبلی را دارد (سایت چیزی را که
 * ادمین پر نکرده نشان نمی‌دهد). مرجعِ طرح: docs/redesign/analysis/B-site.md §3،
 * docs/redesign/DESIGN_CHECKLIST_B_PAGES.md بخشِ S2.
 */
class RedesignBlockShapesTest extends TestCase
{
    use DatabaseTransactions;

    private PageBlockSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new PageBlockSchema;
    }

    private function inValues(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if ($rule instanceof In) {
                return (string) $rule;
            }
        }

        return null;
    }

    #[Test]
    public function the_hero_carries_three_role_cards_whose_role_is_a_closed_choice(): void
    {
        $field = $this->schema->for('hero_media')['fields']['roleCards'] ?? null;
        $this->assertNotNull($field, 'hero_media.roleCards تعریف نشده');
        $this->assertSame(['list', 'role_card'], [$field['type'], $field['of']]);
        $this->assertFalse($field['required'] ?? false, 'ردیفِ قدیمیِ هیرو بدونِ کارت باید معتبر بماند');

        $rules = $this->schema->rulesFor('hero_media');
        $this->assertSame('in:"eliteyar","employer","supplier"', $this->inValues($rules['data.roleCards.*.role']));
        $this->assertContains('array', $rules['data.roleCards.*.paragraphs']);
        $this->assertContains('array', $rules['data.roleCards.*.items']);

        foreach (['role', 'image', 'label', 'head', 'desc', 'title', 'lede', 'paragraphs', 'items', 'cta'] as $key) {
            $this->assertArrayHasKey($key, PageBlockSchema::SHAPES['role_card'], "role_card.{$key}");
        }
    }

    #[Test]
    public function a_story_card_can_carry_its_caption_on_a_glass_capsule(): void
    {
        $rules = $this->schema->rulesFor('story_card');
        $this->assertSame('in:"below","glass"', $this->inValues($rules['data.captionStyle']));
        $this->assertContains('nullable', $rules['data.captionStyle']);
    }

    #[Test]
    public function a_flip_panel_has_an_optional_glass_caption_and_a_product_title_a_closed_font_feature(): void
    {
        $this->assertSame('text', PageBlockSchema::SHAPES['flip_panel']['caption']['type'] ?? null);

        $rules = $this->schema->rulesFor('feature_cards');
        $card = fn ($feat) => ['data' => ['title' => 't', 'cards' => [['titleFeat' => $feat]]]];
        $this->assertFalse(Validator::make($card('"ss01" 1, "ss02" 1'), $rules)->fails());
        $this->assertTrue(Validator::make($card('normal; color:red'), $rules)->fails(), 'CSSِ آزاد نباید از پنل به سایت برسد');
    }

    #[Test]
    public function an_audience_card_may_name_its_role_so_the_site_routes_its_button_by_who_is_looking(): void
    {
        $this->assertSame(
            'in:"eliteyar","employer","supplier"',
            $this->inValues($this->schema->rulesFor('glass_cards')['data.cards.*.role'])
        );
    }

    #[Test]
    public function home_follows_the_design_order_and_the_three_old_story_cards_live_in_the_hero(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $blocks = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_HOME)->orderBy(PageBlock::SORT_ORDER)->get();
        $this->assertSame(
            ['hero_media', 'image_glass_band', 'video', 'callout_list', 'glass_cards', 'story_card', 'story_card', 'faq'],
            $blocks->pluck(PageBlock::TYPE)->all()
        );

        $cards = $blocks[0]->{PageBlock::DATA}['roleCards'];
        $this->assertSame(['eliteyar', 'employer', 'supplier'], array_column($cards, 'role'));
        $this->assertSame(['glass', 'glass'], $blocks->where(PageBlock::TYPE, 'story_card')->map(fn ($b) => $b->{PageBlock::DATA}['captionStyle'])->values()->all());
        $this->assertSame(['eliteyar', 'employer', 'supplier'], array_column($blocks[4]->{PageBlock::DATA}['cards'], 'role'));
    }

    #[Test]
    public function every_seeded_block_passes_the_validation_the_back_office_save_runs(): void
    {
        // D-1: بدنه‌ی چندتکه (پاراگراف → ران) با قاعده‌ی «هر عضو یک رشته» نمی‌خواند، پس ادمین
        // نمی‌توانست همان بلوکِ سیدشده را بی‌خطا ذخیره کند. حالا هر پاراگراف یک رشته است و
        // بخشِ پررنگ بینِ ** و ** می‌آید.
        $seeder = new PageBlocksSeeder;

        foreach (['home' => $seeder->home(), 'podium' => $seeder->podium(), 'steel-kavir' => $seeder->steelKavir()] as $page => $blocks) {
            foreach ($blocks as $i => $block) {
                $v = Validator::make(['data' => $block['data']], $this->schema->rulesFor($block['type']));
                $this->assertFalse($v->fails(), "{$page} #{$i} ({$block['type']}): ".json_encode($v->errors()->all(), JSON_UNESCAPED_UNICODE));
            }
        }
    }

    #[Test]
    public function seeded_copy_is_clean_text_and_media_comes_from_the_cms_store(): void
    {
        $seeder = new PageBlocksSeeder;
        $all = json_encode([$seeder->home(), $seeder->podium(), $seeder->steelKavir()], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('ـ', $all, 'کشیده‌ی دستی در متن — کشیدا را سایت هنگامِ نمایش می‌سازد');
        $this->assertDoesNotMatchRegularExpression('/ۀ|هٔ|ه‌ی /u', $all, 'قاعده‌ی رضا: علامتِ اضافه نوشته نمی‌شود');
        $this->assertStringNotContainsString('staging.elitezone.me', $all);
        $this->assertStringNotContainsString('"/media/steel-kavir', $all, 'عکس‌ها دیگر از باندلِ فرانت نمی‌آیند');

        $images = PageBlocksSeeder::mediaPaths();
        $this->assertCount(47, $images);
        foreach ($images as $path) {
            $this->assertStringStartsWith(PageBlocksSeeder::MEDIA_PREFIX, $path);
        }
    }

    #[Test]
    public function the_seven_technology_posters_carry_their_glass_caption(): void
    {
        $tech = collect((new PageBlocksSeeder)->steelKavir())->firstWhere('type', 'flip_cards')['data']['cards'];

        $this->assertCount(7, $tech);
        $this->assertSame('خطوط نورد دانیلی و پومینی', $tech[0]['caption']);
        $this->assertCount(7, array_filter(array_column($tech, 'caption')));
    }

    #[Test]
    public function the_import_command_copies_every_seeded_image_from_the_prototype_folder(): void
    {
        Storage::fake('public');
        $src = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pb-media-'.uniqid();
        $paths = PageBlocksSeeder::mediaPaths();
        $missing = array_pop($paths);   // one file the folder does not have

        foreach ($paths as $path) {
            $file = $src.'/'.substr($path, strlen(PageBlocksSeeder::MEDIA_PREFIX));
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, 'x');
        }

        $this->artisan('page-blocks:import-media', ['source' => $src])
            ->expectsOutputToContain($missing)
            ->assertExitCode(1);

        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
        Storage::disk('public')->assertMissing($missing);
    }
}

<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use Database\Seeders\PageBlocksSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageBlocksSeederTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function running_it_twice_does_not_duplicate_a_row(): void
    {
        $this->seed(PageBlocksSeeder::class);
        $first = PageBlock::count();

        $this->seed(PageBlocksSeeder::class);

        $this->assertSame($first, PageBlock::count());
    }

    #[Test]
    public function home_gets_every_block_the_page_renders_today(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $types = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_HOME)
            ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all();

        $this->assertSame([
            'hero_media', 'video', 'story_card', 'story_card', 'story_card',
            'image_glass_band', 'callout_list', 'glass_cards',
            'story_card', 'story_card', 'faq',
        ], $types);
    }

    #[Test]
    public function podium_and_steel_kavir_get_every_block_they_render_today(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $this->assertSame(
            ['hero_actions', 'card_grid', 'media_text', 'card_grid', 'flip_card', 'card_grid', 'cta_band'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_PODIUM)
                ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all()
        );

        $this->assertSame(
            ['sk_hero', 'stat_carousel', 'media_text', 'feature_cards', 'media_text', 'banner_carousel', 'flip_cards', 'sk_hero'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
                ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all()
        );
    }

    #[Test]
    public function podium_and_steel_kavir_blocks_carry_the_anchor_id_their_page_uses_today(): void
    {
        // هر بخشِ پادیوم/فولاد کویر یک anchor id دارد (`content.jsx` → هر
        // ثابت، کلیدِ `id`) که `HashScroll` با آن به `/podium#...` یا
        // `/steel-kavirCo#...` اسکرول می‌کند. تا پیش از این، این کلید نه در
        // اسکیما بود نه در سید — یعنی رفتنِ صفحه به سمتِ داده‌محور بی‌صدا
        // این قابلیت را حذف می‌کرد؛ نه دیتای فرانت این کلید را داشت نه
        // دیتای بک‌اند، پس هیچ تستِ مقایسه‌ای چیزی برای دیدن نداشت.
        $this->seed(PageBlocksSeeder::class);

        $this->assertSame(
            ['overview', 'benefits', 'what-is-podium', 'services', 'large-orders', 'trust', 'open-account'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_PODIUM)
                ->orderBy(PageBlock::SORT_ORDER)->get()
                ->map(fn (PageBlock $b) => $b->{PageBlock::DATA}['id'] ?? null)->all()
        );

        $this->assertSame(
            ['overview', 'figures', 'why', 'products', 'transport', 'quality-control', 'technology', 'lusail'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
                ->orderBy(PageBlock::SORT_ORDER)->get()
                ->map(fn (PageBlock $b) => $b->{PageBlock::DATA}['id'] ?? null)->all()
        );
    }

    #[Test]
    public function home_blocks_never_carry_an_id_since_their_components_do_not_take_one(): void
    {
        // هوم استثناست: هیچ‌کدام از کامپوننت‌های هومی پراپِ id نمی‌گیرند
        // (خودِ گزارشِ تسک تأیید کرده)، پس سیدر هم نباید برای هیچ ردیفِ
        // هوم این کلید را بسازد — وگرنه فرمِ ادمین ورودی‌ای نشان می‌داد که
        // هیچ کامپوننتی نمی‌خواندش.
        $this->seed(PageBlocksSeeder::class);

        $anyHasId = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_HOME)->get()
            ->contains(fn (PageBlock $b) => array_key_exists('id', $b->{PageBlock::DATA}));

        $this->assertFalse($anyHasId, 'یک یا چند بلوکِ هوم کلیدِ id دارند، در حالی که هیچ کامپوننتِ هومی این پراپ را نمی‌خواند');
    }
}

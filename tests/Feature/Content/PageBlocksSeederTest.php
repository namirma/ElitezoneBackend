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
            ['sk_hero', 'stat_carousel', 'media_text', 'feature_cards', 'media_text', 'stat_carousel', 'flip_card', 'sk_hero'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
                ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all()
        );
    }
}

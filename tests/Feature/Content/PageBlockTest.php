<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageBlockTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_data_column_round_trips_as_an_array(): void
    {
        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 3,
            PageBlock::DATA => ['title' => 'الیت‌یارها چه کسانی هستند؟', 'items' => ['یک', 'دو']],
        ]);

        $fresh = PageBlock::findOrFail($block->id);

        $this->assertSame('الیت‌یارها چه کسانی هستند؟', $fresh->{PageBlock::DATA}['title']);
        $this->assertSame(['یک', 'دو'], $fresh->{PageBlock::DATA}['items']);
        $this->assertTrue($fresh->{PageBlock::IS_ACTIVE});
    }

    #[Test]
    public function each_page_has_its_own_cache_key(): void
    {
        $this->assertNotSame(
            PageBlock::cacheKeyFor(PageBlock::PAGE_HOME),
            PageBlock::cacheKeyFor(PageBlock::PAGE_PODIUM),
        );
    }

    #[Test]
    public function the_public_endpoint_returns_only_active_blocks_in_order(): void
    {
        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_PODIUM, PageBlock::TYPE => 'cta_band', PageBlock::SORT_ORDER => 2, PageBlock::DATA => ['title' => 'دوم']]);
        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_PODIUM, PageBlock::TYPE => 'hero_actions', PageBlock::SORT_ORDER => 1, PageBlock::DATA => ['title' => 'اول']]);
        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_PODIUM, PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 3, PageBlock::IS_ACTIVE => false, PageBlock::DATA => ['title' => 'خاموش']]);

        $response = $this->getJson('/api/pages/podium/blocks')->assertOk();

        $titles = array_column(array_column($response->json('data'), 'data'), 'title');
        $this->assertSame(['اول', 'دوم'], $titles);
    }

    #[Test]
    public function an_unknown_page_is_rejected_rather_than_returning_an_empty_list(): void
    {
        // فهرستِ خالی یعنی «این صفحه بلوکی ندارد» — ادعای دیگری است.
        $this->getJson('/api/pages/not-a-page/blocks')->assertNotFound();
    }

    #[Test]
    public function saving_a_block_flushes_the_cached_page(): void
    {
        $this->getJson('/api/pages/home/blocks')->assertOk();

        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_HOME, PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 1, PageBlock::DATA => ['title' => 'تازه']]);

        $this->assertCount(1, $this->getJson('/api/pages/home/blocks')->json('data'));
    }
}

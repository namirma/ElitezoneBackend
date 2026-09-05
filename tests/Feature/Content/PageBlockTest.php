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
}

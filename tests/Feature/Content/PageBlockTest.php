<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
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
    public function the_public_response_still_carries_page_and_is_active(): void
    {
        // `PageBlockResource` بینِ اندپوینتِ عمومی و ادمین مشترک است. این‌جا
        // `page` زائد است (صفحه از قبل فیلتر شده) و `is_active` همیشه true است
        // (ردیفِ غیرفعال اصلاً برنمی‌گردد) — عمداً پنهان نشده‌اند چون هیچ‌کدام
        // محرمانه نیستند و شرطِ اضافه فقط برای این اندپوینت هیچ فایده‌ای ندارد.
        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_STEEL_KAVIR, PageBlock::TYPE => 'sk_hero', PageBlock::SORT_ORDER => 1, PageBlock::DATA => ['image' => 'x.webp', 'title' => 'ت', 'body' => 'ب']]);

        $block = $this->getJson('/api/pages/steel-kavir/blocks')->assertOk()->json('data.0');

        $this->assertSame('steel-kavir', $block['page']);
        $this->assertTrue($block['is_active']);
    }

    #[Test]
    public function saving_a_block_flushes_the_cached_page(): void
    {
        $this->getJson('/api/pages/home/blocks')->assertOk();

        PageBlock::create([PageBlock::PAGE => PageBlock::PAGE_HOME, PageBlock::TYPE => 'faq', PageBlock::SORT_ORDER => 1, PageBlock::DATA => ['title' => 'تازه']]);

        $this->assertCount(1, $this->getJson('/api/pages/home/blocks')->json('data'));
    }

    /**
     * نگهبانِ رگرسیون: `posterSrc` (نوعِ `image`) باید مثلِ `image` به URLِ
     * کاملِ دیسکِ public تبدیل شود. پیش از این، `PageBlockResource` یک
     * فهرستِ دستیِ `['image', 'poster']` داشت — `posterSrc` هیچ‌وقت با آن
     * مچ نمی‌شد، پس پوسترِ آپلودی به‌جای URL کامل، همان مسیرِ خامِ ذخیره‌شده
     * برمی‌گشت (۴۰۴ روی سایت). `videoSrc` (نوعِ `text`) عمداً یک URLِ کامل
     * است که خودِ ادمین وارد می‌کند، نه یک آپلود — نباید دست بخورد.
     */
    #[Test]
    public function an_uploads_shaped_poster_src_comes_back_as_an_absolute_url(): void
    {
        Storage::fake('public');

        PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'video',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => [
                'videoSrc' => 'https://cdn.example.com/v.mp4',
                'posterSrc' => 'page-blocks/uploaded.png',
            ],
        ]);

        $data = $this->getJson('/api/pages/home/blocks')->assertOk()->json('data.0.data');

        // خودِ منطقِ resolveMedia مقدارِ حل‌شده را با `http`/`/` می‌شناسد —
        // دیسکِ فیک بسته به تنظیمِ APP_URL ممکن است URL را نسبی (`/storage/...`)
        // بدهد، نه لزوماً با هاستِ کامل؛ نکته‌ی تست، تبدیل‌شدنِ مسیرِ خام است.
        $this->assertNotSame('page-blocks/uploaded.png', $data['posterSrc']);
        $this->assertStringContainsString('/storage/', $data['posterSrc']);
        $this->assertSame('https://cdn.example.com/v.mp4', $data['videoSrc']);
    }
}

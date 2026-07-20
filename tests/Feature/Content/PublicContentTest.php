<?php

namespace Tests\Feature\Content;

use App\Models\Article;
use App\Models\Banner;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the public content contract: active-only visibility, scheduled-publish
 * gating for articles, slug lookup for pages, and the public-settings whitelist.
 */
class PublicContentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // کش لیست‌های فعال بین تست‌ها نباید نشت کند
        Cache::forget(Banner::CACHE_KEY);
        Cache::forget(Faq::CACHE_KEY);
        Cache::forget(Page::CACHE_KEY);
    }

    #[Test]
    public function banners_endpoint_returns_only_active_ordered_by_sort(): void
    {
        Banner::create([Banner::TITLE => 'دوم', Banner::IMAGE => 'banners/b.png', Banner::SORT_ORDER => 2]);
        Banner::create([Banner::TITLE => 'اول', Banner::IMAGE => 'banners/a.png', Banner::SORT_ORDER => 1]);
        Banner::create([Banner::TITLE => 'خاموش', Banner::IMAGE => 'banners/c.png', Banner::IS_ACTIVE => false]);

        $data = $this->getJson('/api/banners')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('اول', $data[0]['title']);
    }

    #[Test]
    public function banners_can_be_filtered_by_position(): void
    {
        Banner::create([Banner::TITLE => 'هوم', Banner::IMAGE => 'banners/h.png', Banner::POSITION => 'home_top']);
        Banner::create([Banner::TITLE => 'سایدبار', Banner::IMAGE => 'banners/s.png', Banner::POSITION => 'sidebar']);

        $data = $this->getJson('/api/banners?position=home_top')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('هوم', $data[0]['title']);
    }

    #[Test]
    public function articles_hide_inactive_and_future_published(): void
    {
        Article::create([Article::TITLE => 'منتشر', Article::SLUG => 'pub-'.Str::random(6), Article::BODY => 'x']);
        Article::create([Article::TITLE => 'خاموش', Article::SLUG => 'off-'.Str::random(6), Article::BODY => 'x', Article::IS_ACTIVE => false]);
        Article::create([Article::TITLE => 'آینده', Article::SLUG => 'fut-'.Str::random(6), Article::BODY => 'x', Article::PUBLISHED_AT => now()->addDay()]);

        $titles = collect($this->getJson('/api/articles')->assertOk()->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('منتشر'));
        $this->assertFalse($titles->contains('خاموش'));
        $this->assertFalse($titles->contains('آینده'));
    }

    #[Test]
    public function article_slug_lookup_404s_for_inactive(): void
    {
        $active = Article::create([Article::TITLE => 'فعال', Article::SLUG => 'act-'.Str::random(6), Article::BODY => 'x']);
        $off = Article::create([Article::TITLE => 'خاموش', Article::SLUG => 'noo-'.Str::random(6), Article::BODY => 'x', Article::IS_ACTIVE => false]);

        $this->getJson('/api/articles/'.$active->slug)->assertOk();
        $this->getJson('/api/articles/'.$off->slug)->assertNotFound();
    }

    #[Test]
    public function faq_returns_only_active_in_sort_order(): void
    {
        Faq::create([Faq::QUESTION => 'ب', Faq::ANSWER => 'x', Faq::SORT_ORDER => 2]);
        Faq::create([Faq::QUESTION => 'الف', Faq::ANSWER => 'x', Faq::SORT_ORDER => 1]);
        Faq::create([Faq::QUESTION => 'خاموش', Faq::ANSWER => 'x', Faq::IS_ACTIVE => false]);

        $data = $this->getJson('/api/faq')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('الف', $data[0]['question']);
    }

    #[Test]
    public function page_slug_lookup_works_and_404s_for_inactive(): void
    {
        $page = Page::create([Page::TITLE => 'درباره ما', Page::SLUG => 'about-'.Str::random(6), Page::BODY => 'x']);
        $off = Page::create([Page::TITLE => 'خاموش', Page::SLUG => 'hid-'.Str::random(6), Page::BODY => 'x', Page::IS_ACTIVE => false]);

        $this->assertSame('درباره ما', $this->getJson('/api/pages/'.$page->slug)->assertOk()->json('data.title'));
        $this->getJson('/api/pages/'.$off->slug)->assertNotFound();
    }

    #[Test]
    public function public_settings_expose_only_whitelisted_keys(): void
    {
        Setting::updateOrCreate([Setting::KEY => 'site_title'], [Setting::VALUE => 'الیت زون']);
        Setting::updateOrCreate([Setting::KEY => 'secret_internal'], [Setting::VALUE => 'hidden']);

        config(['settings.public_keys' => ['site_title']]);

        $data = $this->getJson('/api/settings/public')->assertOk()->json('data');

        $this->assertSame('الیت زون', $data['site_title'] ?? null);
        $this->assertArrayNotHasKey('secret_internal', $data);
    }
}

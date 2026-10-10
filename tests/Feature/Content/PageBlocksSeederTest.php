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
        // ریدیزاین v276: ترتیبِ طرح؛ سه کارتِ داستانیِ نقش‌ها به `hero_media.roleCards` رفتند.
        $this->seed(PageBlocksSeeder::class);

        $types = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_HOME)
            ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all();

        $this->assertSame([
            'hero_media', 'image_glass_band', 'video', 'callout_list', 'glass_cards',
            'story_card', 'story_card', 'faq',
        ], $types);
    }

    #[Test]
    public function podium_and_steel_kavir_get_every_block_they_render_today(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $this->assertSame(
            ['hero_actions', 'card_row', 'media_text', 'card_grid', 'flip_card', 'card_grid', 'cta_band'],
            PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_PODIUM)
                ->orderBy(PageBlock::SORT_ORDER)->pluck(PageBlock::TYPE)->all()
        );

        $this->assertSame(
            ['sk_hero', 'stat_carousel', 'latin_media_text', 'feature_cards', 'latin_media_text', 'banner_carousel', 'flip_cards', 'sk_hero'],
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

    /**
     * `Block8Audiences` از `c527901` دکمه‌ی هر کارت را فقط با `link.to` رندر
     * می‌کند (قاعده‌ی صداقت — دکمه‌ی بی‌مقصد نه). سیدر تا ۱۴۰۵/۰۶/۳۱ لینکی
     * نمی‌کاشت، پس سه کارتِ آبیِ هوم هفده روز بی‌دکمه بودند و هیچ تستی
     * چیزی برای دیدن نداشت. این تست همان قرارداد را روی سید پین می‌کند.
     */
    #[Test]
    public function every_home_glass_card_carries_a_button_destination(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $cards = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_HOME)
            ->where(PageBlock::TYPE, 'glass_cards')->firstOrFail()->{PageBlock::DATA}['cards'];

        $this->assertCount(3, $cards);
        foreach ($cards as $card) {
            $this->assertMatchesRegularExpression('#^/[a-z]#', $card['link']['to'] ?? '', "کارتِ «{$card['title']}» مقصدِ دکمه ندارد — سایت دکمه را رندر نمی‌کند");
            $this->assertSame($card['cta'], $card['link']['label']);
        }
    }

    /**
     * ردیفِ موجود روی استیجینگ/dev از همان سیدرِ بی‌لینک آمده و `firstOrCreate`
     * عوضش نمی‌کند؛ مهاجرتِ `2026_09_22_100000` باید لینکِ خالی را پر کند و به
     * لینکی که ادمین خودش گذاشته دست نزند.
     */
    #[Test]
    public function the_fill_links_migration_fills_only_empty_links_of_seeded_cards(): void
    {
        $legacy = PageBlock::create([
            PageBlock::PAGE => 'home-legacy-test', PageBlock::SORT_ORDER => 1, PageBlock::TYPE => 'glass_cards',
            PageBlock::IS_ACTIVE => true,
            PageBlock::DATA => ['cards' => [
                ['title' => 'a', 'sub' => '', 'body' => '', 'cta' => 'شبکه خود را بسازید'],
                ['title' => 'b', 'sub' => '', 'body' => '', 'cta' => 'خرید خود را آغاز کنید', 'link' => ['label' => 'x', 'to' => '/faq']],
                ['title' => 'c', 'sub' => '', 'body' => '', 'cta' => 'متنی که ادمین خودش نوشته'],
            ]],
        ]);

        $migration = require database_path('migrations/2026_09_22_100000_fill_home_glass_cards_links.php');
        $migration->up();
        $migration->up(); // idempotent

        $cards = $legacy->fresh()->{PageBlock::DATA}['cards'];
        // `assertEquals` و نه `assertSame`: ستونِ `data` از نوعِ JSON است و MySQL 8
        // کلیدهای شیءِ JSON را هنگامِ ذخیره مرتب می‌کند (اول کوتاه‌تر)، پس
        // `{label, to}` به‌صورتِ `{to, label}` برمی‌گردد. ترتیبِ کلید برای فرانت
        // بی‌معناست؛ MariaDBِ محلی ترتیب را نگه می‌دارد و فقط CI این را می‌دید.
        $this->assertEquals(['label' => 'شبکه خود را بسازید', 'to' => '/role'], $cards[0]['link']);
        $this->assertEquals(['label' => 'x', 'to' => '/faq'], $cards[1]['link'], 'لینکِ خودِ ادمین نباید بازنویسی شود');
        $this->assertArrayNotHasKey('link', $cards[2], 'کارتی با متنِ ناشناخته نباید لینکِ حدسی بگیرد');
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

    /**
     * D-1 (ریدیزاین v276): بدنه دیگر «پاراگراف → ران + اندیسِ bodyStrong» نیست، چون ویجتِ textareaِ
     * بک‌آفیس نمی‌توانست آن را ویرایش کند؛ هر پاراگراف یک رشته است و بخشِ پررنگ بینِ ** و ** می‌آید.
     * این سه تست همان کلمه‌های پررنگِ قبلی را روی شکلِ تازه پین می‌کنند.
     */
    private function boldIn(string $paragraph): array
    {
        preg_match_all('/\*\*(.+?)\*\*/u', $paragraph, $m);

        return $m[1];
    }

    #[Test]
    public function sk_why_bolds_exactly_the_four_phrases_the_frontend_default_bolds(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $data = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'why')->firstOrFail()->{PageBlock::DATA};

        $this->assertArrayNotHasKey('bodyStrong', $data);
        $this->assertCount(1, $data['body']);
        $this->assertIsString($data['body'][0]);
        $this->assertSame(
            ['فولاد کویر', 'نزدیک به دو دهه ', 'نگاه توسعه‌محور', 'عملکرد، تداوم تأمین و ارزش بلندمدت'],
            $this->boldIn($data['body'][0])
        );
        $this->assertStringStartsWith('فولاد کویر تنها به ظرفیت بالای تولید متکی نیست؛', str_replace('**', '', $data['body'][0]));
    }

    #[Test]
    public function sk_products_cards_each_bold_exactly_their_own_two_phrases(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $cards = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'products')->firstOrFail()->{PageBlock::DATA}['cards'];
        $this->assertCount(2, $cards);

        $expected = [
            'industrial' => ['محصولات صنعتی فولاد کویر', 'وایرراد و کلاف‌های صنعتی تا مفتول و محصولات مناسب فرایندهای بعدی'],
            'structural' => ['محصولات ساختمانی فولاد کویر', 'از میلگردهای شاخه تا کلاف'],
        ];

        foreach ($cards as $card) {
            $this->assertIsString($card['body'], "کارتِ «{$card['id']}»");
            $this->assertSame($expected[$card['id']], $this->boldIn($card['body']), "کارتِ «{$card['id']}» کلماتِ بولدِ نادرست دارد");
        }
    }

    #[Test]
    public function sk_transport_has_no_bold_and_its_body_is_four_plain_paragraphs(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $data = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'transport')->firstOrFail()->{PageBlock::DATA};

        $this->assertCount(4, $data['body']);
        foreach ($data['body'] as $paragraph) {
            $this->assertIsString($paragraph);
            $this->assertStringNotContainsString('**', $paragraph);
        }
    }
}

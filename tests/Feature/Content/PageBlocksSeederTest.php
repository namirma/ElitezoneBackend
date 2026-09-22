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
        $this->assertSame(['label' => 'شبکه خود را بسازید', 'to' => '/role'], $cards[0]['link']);
        $this->assertSame(['label' => 'x', 'to' => '/faq'], $cards[1]['link'], 'لینکِ خودِ ادمین نباید بازنویسی شود');
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
     * ✅ بستنِ گپِ بولد: `SkStory` (`latin_media_text`) و `SkProducts`
     * (`feature_cards`) تازه به قراردادِ ران‌ایندکسیِ `bodyStrong` تبدیل
     * شده‌اند؛ پیش از این هر دو فقط با ثابتِ هاردکدِ خودشان درست به‌نظر
     * می‌رسیدند. این تست خودِ منطقِ `resolveBodyRuns` را (هم نسخه‌ی
     * پاراگراف/رانِ `SkStory`، هم نسخه‌ی تخت/رانِ `SkProducts`) در PHP
     * تکرار می‌کند تا کلمه‌های واقعاً بولدشده را — نه فقط حضورِ فیلد را —
     * با خروجیِ کامپوننت‌ها مقایسه کند.
     */
    #[Test]
    public function sk_why_body_strong_bolds_exactly_the_four_phrases_the_frontend_default_bolds(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $why = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'why')->firstOrFail();

        $data = $why->{PageBlock::DATA};
        $this->assertSame([0, 2, 4, 6], $data['bodyStrong']);

        // `SkStory/resolveBodyRuns.js`: یک پاراگراف، هشت ران — اندیس روی
        // ران‌های تخت‌شده‌ی همه‌ی پاراگراف‌ها شمرده می‌شود.
        $flatRuns = $data['body'][0];
        $this->assertCount(8, $flatRuns);

        $boldWords = array_intersect_key($flatRuns, array_flip($data['bodyStrong']));

        $this->assertSame([
            0 => 'فولاد کویر',
            2 => 'نزدیک به دو دهه ',
            4 => 'نگاه توسعه‌محور',
            6 => 'عملکرد، تداوم تأمین و ارزش بلندمدت',
        ], $boldWords);

        // الحاقِ هر هشت ران باید عیناً همان متنِ کاملی باشد که پیش از این
        // به‌صورتِ یک رشته‌ی تخت سید می‌شد — یعنی هیچ کاراکتری در تقسیمِ
        // ران‌ها گم یا دوبار نشده.
        $this->assertSame(
            'فولاد کویر تنها به ظرفیت بالای تولید متکی نیست؛ ترکیب توان صنعتی، تنوع گسترده محصولات، کنترل مستمر کیفیت و تجربه نزدیک به دو دهه تولید، این مجموعه را به یکی از نام‌های شاخص صنعت فولاد کشور تبدیل کرده است. ظرفیت سالانه ۹۰۰ هزار تن تولید میلگرد، تولید ۴۰ گرید فولادی و سبدی شامل ۴۵۰ ترکیب گرید–سایز، امکان پاسخ‌گویی به طیف گسترده‌ای از نیازهای ساختمانی و صنعتی را فراهم کرده است. آنچه این توان تولیدی را ارزشمندتر می‌کند، نگاه توسعه‌محور فولاد کویر است؛ از سرمایه‌گذاری در فناوری و کنترل کیفیت تا توسعه محصولات تخصصی‌تر و حرکت به سوی تولید فولادهای ویژه. این مجموعه تلاش کرده است در کنار افزایش ظرفیت، کیفیت پایدار، تنوع انتخاب و قابلیت اطمینان در تولید را نیز حفظ کند؛ ویژگی‌هایی که فولاد کویر را به گزینه‌ای قابل اعتماد برای پروژه‌هایی تبدیل می‌کند که در انتخاب فولاد، تنها به محصول امروز فکر نمی‌کنند، بلکه عملکرد، تداوم تأمین و ارزش بلندمدت را نیز در نظر دارند.',
            implode('', $flatRuns)
        );
    }

    #[Test]
    public function sk_products_cards_each_bold_exactly_their_own_two_phrases(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $products = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'products')->firstOrFail();

        $cards = $products->{PageBlock::DATA}['cards'];
        $this->assertCount(2, $cards);

        // `SkProducts/resolveBodyRuns.js`: یک کارت یک پاراگرافِ تخت است —
        // اندیس مستقیماً روی فهرستِ رانِ همان کارت، نه رانِ تخت‌شده‌ی چند کارت.
        $expected = [
            'industrial' => [0 => 'محصولات صنعتی فولاد کویر', 2 => 'وایرراد و کلاف‌های صنعتی تا مفتول و محصولات مناسب فرآیندهای بعدی'],
            'structural' => [0 => 'محصولات ساختمانی فولاد کویر', 2 => 'از میلگردهای شاخه تا کلاف'],
        ];

        foreach ($cards as $card) {
            $this->assertSame([0, 2], $card['bodyStrong'], "کارتِ «{$card['id']}» باید bodyStrong=[0,2] داشته باشد");

            $boldWords = array_intersect_key($card['body'], array_flip($card['bodyStrong']));
            $this->assertSame($expected[$card['id']], $boldWords, "کارتِ «{$card['id']}» کلماتِ بولدِ نادرست دارد");
        }
    }

    #[Test]
    public function sk_transport_has_no_bold_and_its_body_stays_paragraph_shaped(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $transport = PageBlock::where(PageBlock::PAGE, PageBlock::PAGE_STEEL_KAVIR)
            ->whereJsonContains(PageBlock::DATA.'->id', 'transport')->firstOrFail();

        $data = $transport->{PageBlock::DATA};

        $this->assertSame([], $data['bodyStrong']);
        // چهار پاراگراف، هرکدام دقیقاً یک ران — فهرستِ تختِ چهار رشته
        // `paragraph.map` را روی یک رشته صدا می‌زد و کلِ بلوک را می‌شکست.
        $this->assertCount(4, $data['body']);
        foreach ($data['body'] as $paragraph) {
            $this->assertIsArray($paragraph);
            $this->assertCount(1, $paragraph);
        }
    }
}

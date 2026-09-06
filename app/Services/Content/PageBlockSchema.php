<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Validation\Rule;

/**
 * تنها منبعِ حقیقت برای «هر نوعِ بلوک چه فیلدهایی دارد».
 *
 * سه مصرف‌کننده از همین یک آرایه تغذیه می‌شوند:
 *   ۱. `GET /admin/page-blocks/schema` → بک‌آفیس فرم را خودکار می‌سازد
 *   ۲. `rulesFor()` → اعتبارسنجیِ Store/Update
 *   ۳. خودِ همین فایل، به‌عنوان مستندِ قرارداد
 *
 * بدونِ این تمرکز، هر نوعِ بلوک یک فرمِ دستی در بک‌آفیس می‌خواست و قواعدِ
 * اعتبارسنجی جدا نوشته می‌شدند — دو کپی از یک قرارداد که بی‌صدا واگرا می‌شوند.
 *
 * افزودنِ نوعِ جدید: یک ورودی این‌جا + یک کامپوننت در `blockRegistry.jsx` سایت.
 * بک‌آفیس تغییری نمی‌کند.
 */
final class PageBlockSchema
{
    /** ویجت‌هایی که بک‌آفیس می‌شناسد. نوعِ بیرونِ این فهرست = فرمِ خالی. */
    public const WIDGETS = [
        'text', 'textarea', 'image', 'link', 'list',
        'number', 'color', 'select', 'boolean', 'glass',
    ];

    /**
     * شکلِ عضوِ فهرست‌های آبجکتی — `['of' => 'card']` یعنی هر عضو این کلیدها را دارد.
     *
     * هر مقدار یا نامِ یک ویجت است (رشته‌ی ساده، حالتِ رایج) یا — وقتی ویجت به
     * گزینه نیاز دارد (مثلِ `select`) — همان شکلی که فیلدهای سطحِ بالا دارند:
     * `['type' => ..., 'options' => [...]]`.
     *
     * @var array<string, array<string, string|array{type: string, options?: list<string>}>>
     */
    public const SHAPES = [
        'card' => ['id' => 'text', 'image' => 'image', 'heading' => 'text', 'note' => 'text'],
        'audience' => ['title' => 'text', 'sub' => 'text', 'body' => 'textarea', 'cta' => 'text', 'link' => 'link'],
        'action' => [
            'label' => 'text',
            'href' => 'text',
            'tone' => ['type' => 'select', 'options' => ['solid', 'stroke']],
        ],
        'callout' => ['lead' => 'text', 'rest' => 'text'],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkGlance` می‌خواند
        // (`c.stat` / `c.caption` / `c.body`) — نه value/note.
        'stat' => [
            'id' => 'text',
            'image' => 'image',
            'alt' => 'text',
            'stat' => 'text',
            'label' => 'text',
            'caption' => 'text',
            'body' => 'textarea',
        ],
        // `SkProducts` هر کارت را روی عکسِ خودش رنگ می‌کند: دو رنگِ جدا برای
        // تیتر و زیرتیتر. پس‌زمینه‌ای در کار نیست — کارت خودش عکس است.
        'feature' => [
            'id' => 'text',
            'image' => 'image',
            'alt' => 'text',
            'title' => 'text',
            'subtitle' => 'text',
            'body' => 'textarea',
            // اندیسِ اعضای فهرستِ ران‌های *همین کارت* که باید بولد شوند
            // (`SkProducts.jsx`، `resolveBodyRuns` مصرف می‌کند) — برخلافِ
            // `media_text.bodyStrong` که روی ران‌های تخت‌شده‌ی *همه‌ی
            // پاراگراف‌ها* شمرده می‌شود، یک کارتِ `feature` یک پاراگرافِ
            // تنهاست، پس اندیس مستقیماً روی فهرستِ رانِ همان کارت است — با
            // `captionEnBold`/`media_text.bodyStrong` یکی نیست.
            'bodyStrong' => ['type' => 'list', 'of' => 'number'],
            'titleInk' => 'color',
            'subInk' => 'color',
        ],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkQuality.jsx` می‌خواند
        // (`c.title`/`c.subtitle`/`c.body`/`c.note`؛ :44/:46/:51/:52) — این
        // کارت‌ها نه شکلِ `stat` را دارند نه `feature` را (نه عدد، نه رنگِ
        // تیتر/زیرتیتر؛ فقط یک نوارِ عنوان روی عکس). `note` روی همه‌ی
        // کارت‌ها معنی ندارد (کامپوننت با `c.note &&` گاردش می‌کند)، پس
        // اختیاری ماند.
        'banner_card' => [
            'id' => 'text',
            'image' => 'image',
            'alt' => 'text',
            'title' => 'text',
            'subtitle' => 'text',
            'body' => 'textarea',
            'note' => 'textarea',
        ],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkTechnology.jsx` به
        // `FlipCard` پاس می‌دهد (`c.image`/`c.alt`/`c.heading`/`c.paragraphs`؛
        // :35-38) — `PdScale` (نوعِ `flip_card`) نه `heading` می‌خواند نه
        // بیش از یک کارت.
        'flip_panel' => [
            'id' => 'text',
            'image' => 'image',
            'alt' => 'text',
            'heading' => 'text',
            'paragraphs' => ['type' => 'list', 'of' => 'textarea'],
        ],
    ];

    /** @return array<string, array{label: string, hint: string, fields: array}> */
    public function all(): array
    {
        return [
            // ⚠️ کلیدِ `hero_media` عمداً عوض نشده (رجیستریِ سایت و تستِ پین‌شده به
            // آن وابسته‌اند) اما این بلوک **هیچ عکسی ندارد**: `Block1Hero` دو
            // لوگوی SVG، دو خطِ تیتر و یک دکمه رندر می‌کند و CSSاش هم عکسِ
            // پس‌زمینه ندارد. اسکیمای قبلی یک `image` اجباری می‌خواست که هیچ‌جا
            // مصرف نمی‌شد.
            'hero_media' => [
                'label' => 'هیروِ برند (بدونِ عکس)',
                'hint' => 'دو خطِ تیتر زیرِ نشانِ الیت‌زون، با یک دکمه‌ی فراخوان. این بلوک عکس ندارد — پس‌زمینه‌اش در طراحی است.',
                'fields' => [
                    'headlineLine1' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'headlineLine2' => ['type' => 'text', 'required' => true, 'max' => 160],
                    // دکمه همیشه رندر می‌شود، پس بدونِ مقصد یک لینکِ مرده است.
                    'cta' => ['type' => 'link', 'required' => true],
                ],
            ],

            'video' => [
                'label' => 'ویدیو',
                'hint' => 'ویدیوی تمام‌عرض؛ تا پیش از پخش، عکسِ پوستر دیده می‌شود. عنوانی روی آن نوشته نمی‌شود.',
                'fields' => [
                    // ⚠️ نامِ کلیدها دقیقاً همان propهایی است که `Block2Video`
                    // می‌خواند (`videoSrc` / `posterSrc`، :22-23 و :191-192) —
                    // `src`/`poster` هیچ‌جا مصرف نمی‌شدند.
                    'videoSrc' => ['type' => 'text', 'required' => true],
                    'posterSrc' => ['type' => 'image'],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'story_card' => [
                'label' => 'کارت داستانی',
                'hint' => 'کارت عکس که با کلیک به پنلِ متنی برمی‌گردد. پنجره‌ی هوم.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'captionFa' => ['type' => 'text', 'max' => 120],
                    'captionEn' => ['type' => 'text', 'max' => 120],
                    // اندیس‌های کاراکتری داخلِ `captionEn` که باید بولد شوند
                    // (`StoryCard.jsx:47` می‌خواند، `splitBoldChars` مصرف می‌کند) —
                    // تنها راهِ ادمین برای بولدکردنِ بخشی از یک متنِ ساده، بدونِ JSX.
                    'captionEnBold' => ['type' => 'list', 'of' => 'number'],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'lede' => ['type' => 'textarea', 'max' => 600],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea'],
                    'items' => ['type' => 'list', 'of' => 'text'],
                    'link' => ['type' => 'link'],
                ],
            ],

            'image_glass_band' => [
                'label' => 'عکس با نوارِ شیشه‌ای',
                'hint' => 'عکسِ تمام‌عرض با یک نوارِ شیشه‌ای روی آن؛ هر جمله یک خطِ نوار است.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'eyebrow' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'callout_list' => [
                'label' => 'فهرستِ جملاتِ کوتاه',
                'hint' => 'جمله‌ها یکی‌یکی با اسکرول ظاهر می‌شوند. کاراکترِ «ـ» داخلِ متن، کشیدگیِ طراحی است — پاکش نکنید.',
                'fields' => [
                    'callouts' => ['type' => 'list', 'of' => 'callout', 'required' => true],
                ],
            ],

            'glass_cards' => [
                'label' => 'کارت‌های شیشه‌ای',
                'hint' => 'چند کارتِ شیشه‌ای کنارِ هم، هرکدام برای یک مخاطب: عنوان، توضیح و یک لینک.',
                'fields' => [
                    'cards' => ['type' => 'list', 'of' => 'audience', 'required' => true],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'faq' => [
                'label' => 'سؤالات متداول',
                'hint' => 'آکاردئونِ سؤال‌ها. خودِ سؤال‌ها این‌جا نوشته نمی‌شوند — از دسته‌بندی‌های FAQ خوانده می‌شوند. فیلدِ اسلاگِ دسته فعلاً هیچ اثری روی صفحه‌ی سایت ندارد؛ سایت همیشه همه‌ی دسته‌ها را با فیلترِ pill نشان می‌دهد.',
                'fields' => [
                    'heading' => ['type' => 'text'],
                    'intro' => ['type' => 'textarea', 'max' => 400],
                    // ⚠️ `Block11Faq.jsx` اصلاً `categorySlug` را نمی‌خواند —
                    // نه خالی یعنی «همه»، نه پرشده یعنی «یک دسته»؛ همیشه همه‌ی
                    // دسته‌ها را با فیلترِ pill (پیش‌فرض «همه») نشان می‌دهد. این
                    // فیلد فعلاً هیچ اثری روی صفحه‌ی رندرشده ندارد.
                    'categorySlug' => ['type' => 'text'],
                ],
            ],

            'hero_actions' => [
                'label' => 'هیرو با دکمه',
                'hint' => 'هیروِ عکس‌دار با چند جمله و یک یا دو دکمه‌ی فراخوان روی آن.',
                'fields' => [
                    // اختیاری. اگر پر شود، همان مقدار روی `<section id="...">`ی
                    // خودِ سایت می‌نشیند (`PdHero.jsx:22`) تا لینکی مثلِ
                    // `/podium#overview` بتواند با `HashScroll` (که در
                    // `AppLayout` نصب است) به همین بخش اسکرول کند. این فیلد
                    // فقط روی نوع‌هایی اضافه شده که کامپوننتشان واقعاً `id`
                    // می‌گیرد و آن را روی `<section>` می‌گذارد — بلوک‌های هوم
                    // (`hero_media`, `video`, `story_card`, `image_glass_band`,
                    // `callout_list`, `glass_cards`, `faq`) اصلاً پراپِ id
                    // نمی‌گیرند، پس این فیلد آن‌جا نیست.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'subtitle' => ['type' => 'text'],
                    // هر دو را کامپوننت بدونِ گارد `map` می‌کند — خالی‌بودنشان
                    // صفحه را می‌شکند، پس اجباری‌اند.
                    'lines' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                    'actions' => ['type' => 'list', 'of' => 'action', 'required' => true, 'max' => 2],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'card_grid' => [
                'label' => 'شبکه‌ی کارت',
                'hint' => 'چند کارتِ عکس + عنوان + زیرنویس، با تونِ روشن/تیره و جهتِ چیدمان.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdCardGrid.jsx:19` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'tone' => ['type' => 'select', 'options' => ['light', 'dark'], 'required' => true],
                    'flow' => ['type' => 'select', 'options' => ['rtl', 'ltr'], 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'card', 'required' => true],
                ],
            ],

            'media_text' => [
                'label' => 'عکس و متن',
                'hint' => 'عکس در یک سو و عنوان با چند پاراگراف در سوی دیگر.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // این نوع را هم `PdIntro.jsx:20` هم `SkStory.jsx:24`
                    // می‌خوانند؛ هر دو آن را روی `<section>` می‌گذارند.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                    // اندیسِ اعضای فهرستِ paragraphs/runsِ فلت‌شده‌ی `body` که باید
                    // بولد شوند (`PdIntro.jsx:16`، `resolveBodyRuns` مصرف می‌کند) —
                    // اندیسِ کاراکتر نیست، اندیسِ «ران» است؛ با `captionEnBold` بالا
                    // یکی نیست.
                    'bodyStrong' => ['type' => 'list', 'of' => 'number'],
                ],
            ],

            'flip_card' => [
                'label' => 'کارتِ برگردان',
                'hint' => 'یک کارتِ عکس که با کلیک برمی‌گردد و پاراگراف‌های پشتش را نشان می‌دهد.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdScale.jsx:19` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                ],
            ],

            'cta_band' => [
                'label' => 'نوارِ پایانی',
                'hint' => 'عکسِ تمام‌عرض با متن و یک دکمه روی آن.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdClosing.jsx:21` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text', 'max' => 160],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'lead' => ['type' => 'textarea', 'max' => 400],
                    // `PdClosing.jsx:27` بدونِ گارد `action.href` را می‌خواند —
                    // نبودِ `action` استثنا پرت می‌کند و کلِ صفحه را سفید می‌کند.
                    'action' => ['type' => 'link', 'required' => true],
                    'fineprint' => ['type' => 'textarea', 'max' => 400],
                    // `PdClosing` هیچ سطحِ شیشه‌ای ندارد (نه GlassPanel، نه
                    // backdrop-filter در CSSاش) — فیلدِ glass این‌جا مرده بود.
                ],
            ],

            'sk_hero' => [
                'label' => 'هیروِ فولاد کویر',
                'hint' => 'هیروِ عکس‌دار با نوارِ شیشه‌ای؛ یک کپشنِ لاتینِ اختیاری زیرِ تیتر و یک پاراگراف. «ظاهرشدن با اسکرول» را برای بلوکِ بالای صفحه خاموش بگذارید.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkHero.jsx:28` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'captionEn' => ['type' => 'text'],
                    'body' => ['type' => 'textarea', 'required' => true],
                    'reveal' => ['type' => 'boolean'],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'stat_carousel' => [
                'label' => 'چرخ‌وفلکِ کارت',
                'hint' => 'کارت‌های عددی که افقی می‌چرخند: هر کارت یک عکس، یک عدد، یک برچسب، یک زیرنویسِ کوتاه و یک پاراگراف زیرِ عکس.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkGlance.jsx:18` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'stat', 'required' => true],
                ],
            ],

            'feature_cards' => [
                'label' => 'دو کارتِ محصول',
                'hint' => 'کارت‌های بزرگِ محصول؛ نوشته روی خودِ عکس می‌نشیند، پس رنگِ تیتر و زیرتیترِ هر کارت جدا انتخاب می‌شود.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkProducts.jsx:31` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'feature', 'required' => true],
                ],
            ],

            // ⚠️ قبلاً زیرِ نوعِ `stat_carousel` سید می‌شد چون هر دو یک
            // چرخ‌وفلکِ کارت‌اند (شباهتِ ظاهری)، ولی `SkQuality.jsx` نه
            // `stat`/`label`/`caption` می‌خواند نه هیچ عددی — کارت‌هایش
            // `title`/`subtitle`/`body`/`note` هستند (شکلِ `banner_card`
            // بالا). سیدِ قبلی این فیلدها را زیرِ اسکیمای `stat_carousel`
            // ذخیره می‌کرد، یعنی `SkGlance` (مصرف‌کننده‌ی واقعیِ
            // `stat_carousel`) داده‌ی `SkQuality` را می‌گرفت.
            'banner_carousel' => [
                'label' => 'چرخ‌وفلکِ کارتِ عنوان‌دار',
                'hint' => 'کارت‌های عکس‌دار که افقی می‌چرخند: هر کارت یک عکس با نوارِ عنوان/زیرعنوان روی آن و یک پاراگراف زیرِ عکس؛ یک یادداشتِ اختیاری هم می‌توان فقط به کارت‌های خاصی اضافه کرد.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkQuality.jsx:27` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'banner_card', 'required' => true],
                ],
            ],

            // ⚠️ قبلاً زیرِ نوعِ `flip_card` سید می‌شد (هر دو کارتِ
            // برگردان‌اند)، اما `flip_card`/`PdScale` یک کارتِ تنهاست و
            // `heading` نمی‌خواند؛ `SkTechnology.jsx` هفت کارت با
            // `heading`/`paragraphs` به `FlipCard` می‌دهد (شکلِ `flip_panel`
            // بالا). سیدِ قبلی این هفت کارت را زیرِ اسکیمای تک‌کارتیِ
            // `flip_card` ذخیره می‌کرد، یعنی `PdScale` (مصرف‌کننده‌ی واقعیِ
            // `flip_card`) داده‌ی هفت‌کارتیِ `SkTechnology` را می‌گرفت.
            'flip_cards' => [
                'label' => 'چند کارتِ برگردان',
                'hint' => 'چند کارتِ عکس که هرکدام جدا با کلیک برمی‌گردد و پاراگراف‌های خودش را نشان می‌دهد — برخلافِ «کارتِ برگردان» که فقط یک کارتِ تنها دارد.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkTechnology.jsx:25` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'flip_panel', 'required' => true],
                ],
            ],

            // نامِ کلیدها دقیقاً همان propهایی است که `PdBenefits.jsx` می‌خواند
            // (`id`/`title`/`cards`، :17) — بدونِ `tone` یا `flow`. ردیف همیشه
            // راست‌به‌چپ رندر می‌شود، بدونِ هیچ overrideی (:7-16) — برخلافِ
            // `PdCardGrid`، این کامپوننت اصلاً پراپِ تون/جهت نمی‌گیرد.
            //
            // ⚠️ قبلاً زیرِ نوعِ `card_grid` سید می‌شد چون کارت‌هایش شکلِ `card`
            // را دارند (شباهتِ ظاهری با `PdCardGrid`)، یعنی `PdCardGrid`
            // (مصرف‌کننده‌ی واقعیِ `card_grid`) داده‌ی این بلوک را می‌گرفت —
            // نتیجه: بخشِ «مزایا»ی پادیوم با ارتفاع و چیدمانِ اشتباه رندر
            // می‌شد (۴ کارت در ۳+۱ به‌جای یک ردیف).
            'card_row' => [
                'label' => 'ردیفِ کارت',
                'hint' => 'چند کارتِ عکس + عنوان + زیرنویس، همیشه در یک ردیفِ ثابتِ راست‌به‌چپ — بدونِ کنترلِ تونِ روشن/تیره یا جهتِ چیدمان، برخلافِ «شبکه‌ی کارت».',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdBenefits.jsx:21` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'cards' => ['type' => 'list', 'of' => 'card', 'required' => true],
                ],
            ],

            // نامِ کلیدها دقیقاً همان propهایی است که `SkStory.jsx` می‌خواند
            // (`id`/`title`/`image`/`alt`/`body`/`bodyStrong`، :21) — همان
            // جفتِ `body`/`bodyStrong`ی که `media_text` هم اعلام می‌کند؛ هر دو
            // کامپوننت `resolveBodyRuns` را با یک قراردادِ ران‌های تخت‌شده
            // مصرف می‌کنند (تأییدِ دستی، نه فرض).
            //
            // ⚠️ قبلاً زیرِ همان `media_text` سید می‌شد چون فیلدهایش عیناً
            // یکی است، اما `SkStory` یک پاسِ `latinize()` (`latinRuns.jsx`،
            // :5 و :44-48) روی هر رانِ متن اجرا می‌کند — هر عبارتِ لاتین را با
            // فونتِ سان‌فرانسیسکو جدا می‌کند، کاری که `PdIntro` اصلاً نمی‌کند.
            // دو کامپوننتِ متفاوت با فیلدهایی که این‌بار *یکسان* از آب درآمدند؛
            // فرقشان شکلِ داده نیست، رفتارِ رندر است — و همین برای لازم‌بودنِ
            // یک نوعِ جدا کافی است: با یک کلیدِ مشترک، رجیستریِ سایت فقط یکی
            // از این دو رفتار را نگه می‌داشت.
            'latin_media_text' => [
                'label' => 'عکس و متنِ لاتین‌دار',
                'hint' => 'مثلِ «عکس و متن»، با یک تفاوت: هر عبارتِ لاتینِ داخلِ متن (کدها، اعداد لاتین، اصطلاحاتِ انگلیسی) با فونتِ جداگانه‌ای نشان داده می‌شود.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkStory.jsx:25` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190],
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                    // اندیسِ اعضای فهرستِ paragraphs/runsِ فلت‌شده‌ی `body` که باید
                    // بولد شوند — دقیقاً همان قراردادِ `media_text.bodyStrong`
                    // بالا (`SkStory.jsx:41`، `resolveBodyRuns` مصرف می‌کند).
                    'bodyStrong' => ['type' => 'list', 'of' => 'number'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    /** @return array{label: string, hint: string, fields: array} */
    public function for(string $type): array
    {
        return $this->all()[$type] ?? ['label' => '', 'hint' => '', 'fields' => []];
    }

    /**
     * قواعدِ لاراول، ساخته‌شده از همان اسکیمایی که فرمِ پنل را می‌سازد.
     *
     * نوعِ ناشناخته آرایه‌ی خالی می‌دهد و **نه** «هر چیزی مجاز است» — کنترلر
     * پیش از این متد خودِ `type` را با `Rule::in()` رد می‌کند.
     */
    public function rulesFor(string $type): array
    {
        if (! $this->has($type)) {
            return [];
        }

        $rules = [];

        foreach ($this->for($type)['fields'] as $name => $field) {
            $key = 'data.'.$name;
            $isRequired = (bool) ($field['required'] ?? false);
            $required = $isRequired ? 'required' : 'nullable';

            match ($field['type']) {
                'list' => $rules += $this->listRules($key, $field, $isRequired),
                'link' => $rules += [
                    $key => [$required, 'array'],
                    $key.'.label' => ['nullable', 'string', 'max:120'],
                    $key.'.to' => ['nullable', 'string', 'max:2048'],
                ],
                'glass' => $rules[$key] = [$required, 'array'],
                'number' => $rules[$key] = [$required, 'numeric'],
                'boolean' => $rules[$key] = [$required, 'boolean'],
                'select' => $rules[$key] = [$required, 'string', Rule::in($this->selectOptions($field, "{$type}.{$name}"))],
                'image' => $rules[$key] = [$required, 'string', 'max:2048'],
                default => $rules[$key] = [$required, 'string', 'max:'.($field['max'] ?? 5000)],
            };
        }

        return $rules;
    }

    /**
     * فهرستِ رشته‌ای یک قاعده می‌گیرد؛ فهرستِ آبجکتی، یکی به ازای هر کلیدِ شکل.
     *
     * `$required` همان پرچمِ خودِ فیلد است — قاعده‌ی سطحِ آرایه باید دقیقاً همان
     * چیزی را بگوید که اسکیما توصیف کرده، نه همیشه «nullable».
     */
    private function listRules(string $key, array $field, bool $required): array
    {
        $outerRules = [$required ? 'required' : 'nullable', 'array'];

        if (isset($field['max'])) {
            $outerRules[] = 'max:'.$field['max'];
        }

        $shape = self::SHAPES[$field['of']] ?? null;

        if ($shape === null) {
            // `of` این‌جا نامِ یک ویجتِ اسکالر است (نه کلیدِ SHAPES). پیش از این
            // هر عضوی — حتی فهرستِ `number` مثلِ `captionEnBold`/`bodyStrong` —
            // همیشه به‌عنوان رشته اعتبارسنجی می‌شد، یعنی هر اندیس رشته می‌خواست.
            $memberRule = $field['of'] === 'number'
                ? ['required', 'numeric']
                : ['required', 'string', 'max:5000'];

            return [$key => $outerRules, $key.'.*' => $memberRule];
        }

        $rules = [$key => $outerRules];

        foreach ($shape as $name => $member) {
            $memberKey = $key.'.*.'.$name;

            if (is_array($member) && $member['type'] === 'list') {
                // عضوی از شکل که خودش فهرست است — مثلِ `flip_panel.paragraphs`
                // (هر کارت چند پاراگراف دارد). با فراخوانیِ بازگشتیِ همین متد
                // روی کلیدِ تودرتو، هم قاعده‌ی سطحِ آرایه و هم قاعده‌ی عضو را
                // می‌گیریم — بدونِ کپیِ منطق. عضوِ فهرست هرگز خودش اجباری نیست؛
                // اجباری‌بودنِ کلِ فهرستِ بیرونی (بالا) کافی است.
                $rules += $this->listRules($memberKey, $member, false);

                continue;
            }

            if (is_array($member)) {
                // تنها شکلِ آرایه‌ایِ دیگر، select با options است.
                $rules[$memberKey] = ['nullable', 'string', Rule::in($this->selectOptions($member, "{$field['of']}.{$name}"))];

                continue;
            }

            $rules[$memberKey] = $member === 'link'
                ? ['nullable', 'array']
                : ['nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * گزینه‌های یک فیلدِ select را برمی‌گرداند؛ نبودنشان خطای برنامه‌نویس است،
     * نه ورودیِ کاربر — پس با استثنا متوقف می‌شود، نه با قاعده‌ای که خاموش
     * همه‌چیز را رد می‌کند (`Rule::in(null)`).
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function selectOptions(array $field, string $context): array
    {
        $options = $field['options'] ?? null;

        if (empty($options)) {
            throw new \InvalidArgumentException(
                "فیلدِ select با نامِ «{$context}» گزینه‌ای (options) تعریف نکرده — این خطای برنامه‌نویس است."
            );
        }

        return $options;
    }
}

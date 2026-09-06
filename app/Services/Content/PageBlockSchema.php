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
     * هر مقدار همیشه آرایه است: حداقل `type` (نامِ ویجت) و `label` (متنِ
     * فارسیِ کنارِ ورودی در بک‌آفیس)، به‌علاوه‌ی `options`/`of` وقتی ویجت به
     * آن‌ها نیاز دارد — دقیقاً همان کلیدهایی که فیلدهای سطحِ بالا دارند. پیش
     * از این تسک بعضی اعضا رشته‌ی خام بودند (نامِ ویجت به‌تنهایی)؛ برای اینکه
     * هرکدام بتواند `label` داشته باشد همه به همین آرایه تبدیل شدند —
     * `AdminPageBlockController::normalizedShapes()` (خارج از محدوده‌ی این
     * تغییر) هنوز هر دو شکل را می‌پذیرد و آرایه را دست‌نخورده سرو می‌کند.
     *
     * تکرارِ لیبلِ `id` روی چند شکل (`card`/`stat`/`feature`/`banner_card`/
     * `flip_panel`) عمدی است: در هرکدام همان معنیِ «شناسه‌ی داخلیِ این عضوِ
     * فهرست» را دارد، نه لنگرِ صفحه — نبایدِ آن‌ها را با فیلدِ سطحِ بالا به
     * نامِ `id` (لنگرِ `<section>`) اشتباه گرفت.
     *
     * @var array<string, array<string, array{type: string, label: string, hint?: string, options?: list<string>, of?: string}>>
     */
    public const SHAPES = [
        'card' => [
            'id' => ['type' => 'text', 'label' => 'شناسه‌ی کارت', 'hint' => 'فقط برای شناساییِ داخلیِ این کارت در فهرست است؛ لنگرِ صفحه نیست.'],
            'image' => ['type' => 'image', 'label' => 'عکسِ کارت'],
            'heading' => ['type' => 'text', 'label' => 'عنوانِ کارت'],
            'note' => ['type' => 'text', 'label' => 'توضیحِ کوتاهِ کارت'],
        ],
        'audience' => [
            'title' => ['type' => 'text', 'label' => 'عنوانِ کارت'],
            'sub' => ['type' => 'text', 'label' => 'زیرعنوانِ کارت'],
            'body' => ['type' => 'textarea', 'label' => 'متنِ کارت'],
            'cta' => ['type' => 'text', 'label' => 'متنِ دکمه', 'hint' => 'اگر در «لینکِ دکمه» متنی وارد نشود، همین متن روی دکمه نمایش داده می‌شود.'],
            'link' => ['type' => 'link', 'label' => 'لینکِ دکمه', 'hint' => 'دکمه فقط وقتی نمایش داده می‌شود که این لینک پر شده باشد.'],
        ],
        'action' => [
            'label' => ['type' => 'text', 'label' => 'متنِ دکمه'],
            'href' => ['type' => 'text', 'label' => 'آدرسِ دکمه', 'hint' => 'لینک همیشه در تبِ جدید باز می‌شود.'],
            'tone' => ['type' => 'select', 'options' => ['solid', 'stroke'], 'label' => 'ظاهرِ دکمه', 'hint' => 'توپر (solid) یا خط‌دار (stroke).'],
        ],
        'callout' => [
            'lead' => ['type' => 'text', 'label' => 'بخشِ برجسته‌ی جمله', 'hint' => 'قسمتِ اولِ جمله، که با رنگِ متفاوت نمایش داده می‌شود.'],
            'rest' => ['type' => 'text', 'label' => 'ادامه‌ی جمله'],
        ],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkGlance` می‌خواند
        // (`c.stat` / `c.caption` / `c.body`) — نه value/note.
        'stat' => [
            'id' => ['type' => 'text', 'label' => 'شناسه‌ی کارت', 'hint' => 'فقط برای شناساییِ داخلیِ این کارت در فهرست است؛ لنگرِ صفحه نیست.'],
            'image' => ['type' => 'image', 'label' => 'عکسِ کارت'],
            'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
            'stat' => ['type' => 'text', 'label' => 'عددِ اصلی', 'hint' => 'رقم یا آماری که با فونتِ بزرگ روی کارت نمایش داده می‌شود، مثلِ «۷۰,۰۰۰+».'],
            'label' => ['type' => 'text', 'label' => 'برچسبِ زیرِ عدد'],
            'caption' => ['type' => 'text', 'label' => 'کپشنِ کوتاه'],
            'body' => ['type' => 'textarea', 'label' => 'پاراگرافِ زیرِ عکس'],
        ],
        // `SkProducts` هر کارت را روی عکسِ خودش رنگ می‌کند: دو رنگِ جدا برای
        // تیتر و زیرتیتر. پس‌زمینه‌ای در کار نیست — کارت خودش عکس است.
        'feature' => [
            'id' => ['type' => 'text', 'label' => 'شناسه‌ی کارت', 'hint' => 'فقط برای شناساییِ داخلیِ این کارت در فهرست است؛ لنگرِ صفحه نیست.'],
            'image' => ['type' => 'image', 'label' => 'عکسِ کارت'],
            'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
            'title' => ['type' => 'text', 'label' => 'عنوانِ رویِ عکس'],
            'subtitle' => ['type' => 'text', 'label' => 'زیرعنوانِ رویِ عکس'],
            'body' => ['type' => 'textarea', 'label' => 'پاراگرافِ زیرِ عکس'],
            // اندیسِ اعضای فهرستِ ران‌های *همین کارت* که باید بولد شوند
            // (`SkProducts.jsx`، `resolveBodyRuns` مصرف می‌کند) — برخلافِ
            // `media_text.bodyStrong` که روی ران‌های تخت‌شده‌ی *همه‌ی
            // پاراگراف‌ها* شمرده می‌شود، یک کارتِ `feature` یک پاراگرافِ
            // تنهاست، پس اندیس مستقیماً روی فهرستِ رانِ همان کارت است — با
            // `captionEnBold`/`media_text.bodyStrong` یکی نیست.
            'bodyStrong' => [
                'type' => 'list', 'of' => 'number',
                'label' => 'اندیس‌های بولدِ متن',
                'hint' => 'اندیسِ بخش‌هایی از «پاراگرافِ زیرِ عکسِ همین کارت» که باید پررنگ نمایش داده شوند — عدد جایگاه است، نه خودِ متن؛ فقط به همین کارت مربوط است.',
            ],
            'titleInk' => ['type' => 'color', 'label' => 'رنگِ عنوانِ رویِ عکس', 'hint' => 'چون هر کارت روی عکسِ متفاوتی می‌نشیند، رنگِ متنِ عنوان جداگانه انتخاب می‌شود.'],
            'subInk' => ['type' => 'color', 'label' => 'رنگِ زیرعنوانِ رویِ عکس'],
        ],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkQuality.jsx` می‌خواند
        // (`c.title`/`c.subtitle`/`c.body`/`c.note`؛ :44/:46/:51/:52) — این
        // کارت‌ها نه شکلِ `stat` را دارند نه `feature` را (نه عدد، نه رنگِ
        // تیتر/زیرتیتر؛ فقط یک نوارِ عنوان روی عکس). `note` روی همه‌ی
        // کارت‌ها معنی ندارد (کامپوننت با `c.note &&` گاردش می‌کند)، پس
        // اختیاری ماند.
        'banner_card' => [
            'id' => ['type' => 'text', 'label' => 'شناسه‌ی کارت', 'hint' => 'فقط برای شناساییِ داخلیِ این کارت در فهرست است؛ لنگرِ صفحه نیست.'],
            'image' => ['type' => 'image', 'label' => 'عکسِ کارت'],
            'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
            'title' => ['type' => 'text', 'label' => 'عنوانِ رویِ عکس'],
            'subtitle' => ['type' => 'text', 'label' => 'زیرعنوانِ رویِ عکس'],
            'body' => ['type' => 'textarea', 'label' => 'پاراگرافِ زیرِ عکس'],
            'note' => ['type' => 'textarea', 'label' => 'یادداشتِ اختیاری', 'hint' => 'فقط وقتی پر شود زیرِ متنِ اصلی نمایش داده می‌شود؛ لازم نیست همه‌ی کارت‌ها داشته باشند.'],
        ],
        // نامِ کلیدها دقیقاً همان چیزی است که `SkTechnology.jsx` به
        // `FlipCard` پاس می‌دهد (`c.image`/`c.alt`/`c.heading`/`c.paragraphs`؛
        // :35-38) — `PdScale` (نوعِ `flip_card`) نه `heading` می‌خواند نه
        // بیش از یک کارت.
        'flip_panel' => [
            'id' => ['type' => 'text', 'label' => 'شناسه‌ی کارت', 'hint' => 'فقط برای شناساییِ داخلیِ این کارت در فهرست است؛ لنگرِ صفحه نیست.'],
            'image' => ['type' => 'image', 'label' => 'عکسِ کارت'],
            'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
            'heading' => ['type' => 'text', 'label' => 'عنوانِ پشتِ کارت'],
            'paragraphs' => ['type' => 'list', 'of' => 'textarea', 'label' => 'پاراگراف‌های پشتِ کارت'],
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
                    'headlineLine1' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'خطِ اولِ تیتر'],
                    'headlineLine2' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'خطِ دومِ تیتر'],
                    // دکمه همیشه رندر می‌شود، پس بدونِ مقصد یک لینکِ مرده است.
                    'cta' => ['type' => 'link', 'required' => true, 'label' => 'دکمه‌ی فراخوان'],
                ],
            ],

            'video' => [
                'label' => 'ویدیو',
                'hint' => 'ویدیوی تمام‌عرض؛ تا پیش از پخش، عکسِ پوستر دیده می‌شود. عنوانی روی آن نوشته نمی‌شود.',
                'fields' => [
                    // ⚠️ نامِ کلیدها دقیقاً همان propهایی است که `Block2Video`
                    // می‌خواند (`videoSrc` / `posterSrc`، :22-23 و :191-192) —
                    // `src`/`poster` هیچ‌جا مصرف نمی‌شدند.
                    'videoSrc' => ['type' => 'text', 'required' => true, 'label' => 'نشانی فایلِ ویدیو', 'hint' => 'آدرسِ (URL) فایلِ ویدیویی که در این بلوک پخش می‌شود — آپلودِ مستقیم نیست.'],
                    'posterSrc' => ['type' => 'image', 'label' => 'عکسِ پوستر', 'hint' => 'پیش از پخش، به‌جای ویدیو نمایش داده می‌شود.'],
                    'glass' => ['type' => 'glass', 'label' => 'تنظیماتِ نمای شیشه‌ای', 'hint' => 'روی نوارِ کنترل‌های ویدیو (نوارِ پیشرفت، دکمه‌ی پخش و دکمه‌ی صدا) اثر می‌گذارد — رنگ، شفافیت، بلور، عمق و رینگِ نورِ همان نوار را از این‌جا تنظیم می‌کنید.'],
                ],
            ],

            'story_card' => [
                'label' => 'کارت داستانی',
                'hint' => 'کارت عکس که با کلیک به پنلِ متنی برمی‌گردد. پنجره‌ی هوم.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکسِ کارت'],
                    'alt' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'متن جایگزین عکس (alt)'],
                    'captionFa' => ['type' => 'text', 'max' => 120, 'label' => 'کپشنِ فارسی'],
                    'captionEn' => ['type' => 'text', 'max' => 120, 'label' => 'کپشنِ لاتین'],
                    // اندیس‌های کاراکتری داخلِ `captionEn` که باید بولد شوند
                    // (`StoryCard.jsx:47` می‌خواند، `splitBoldChars` مصرف می‌کند) —
                    // تنها راهِ ادمین برای بولدکردنِ بخشی از یک متنِ ساده، بدونِ JSX.
                    'captionEnBold' => [
                        'type' => 'list', 'of' => 'number',
                        'label' => 'اندیس‌های بولدِ کپشنِ لاتین',
                        'hint' => 'اندیسِ حرف‌هایی از «کپشنِ لاتین» که باید پررنگ نمایش داده شوند — عدد جایگاهِ حرف است، نه خودِ متن.',
                    ],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'عنوانِ پنلِ باز'],
                    'lede' => ['type' => 'textarea', 'max' => 600, 'label' => 'پاراگرافِ آغازین', 'hint' => 'پاراگرافِ اول، با فونتی بزرگ‌تر از بقیه‌ی متن.'],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea', 'label' => 'پاراگراف‌های متن'],
                    'items' => ['type' => 'list', 'of' => 'text', 'label' => 'موارد فهرست'],
                    'link' => ['type' => 'link', 'label' => 'لینکِ «بیشتر بدانید»'],
                ],
            ],

            'image_glass_band' => [
                'label' => 'عکس با نوارِ شیشه‌ای',
                'hint' => 'عکسِ تمام‌عرض با یک نوارِ شیشه‌ای روی آن؛ هر جمله یک خطِ نوار است.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکسِ تمام‌عرض'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'eyebrow' => ['type' => 'text', 'label' => 'جمله‌ی بالای عنوان'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true, 'label' => 'جمله‌های نوار', 'hint' => 'هر عضوِ فهرست یک خط از نوارِ شیشه‌ای روی عکس است.'],
                    'glass' => ['type' => 'glass', 'label' => 'تنظیماتِ نمای شیشه‌ای', 'hint' => 'روی همین نوارِ شیشه‌ایِ رویِ عکس اثر می‌گذارد — رنگ، شفافیت، بلور، عمق و رینگِ نورِ نوار را از این‌جا تنظیم می‌کنید.'],
                ],
            ],

            'callout_list' => [
                'label' => 'فهرستِ جملاتِ کوتاه',
                'hint' => 'جمله‌ها یکی‌یکی با اسکرول ظاهر می‌شوند. کاراکترِ «ـ» داخلِ متن، کشیدگیِ طراحی است — پاکش نکنید.',
                'fields' => [
                    'callouts' => ['type' => 'list', 'of' => 'callout', 'required' => true, 'label' => 'فهرستِ جمله‌ها'],
                ],
            ],

            'glass_cards' => [
                'label' => 'کارت‌های شیشه‌ای',
                'hint' => 'چند کارتِ شیشه‌ای کنارِ هم، هرکدام برای یک مخاطب: عنوان، توضیح و یک لینک.',
                'fields' => [
                    'cards' => ['type' => 'list', 'of' => 'audience', 'required' => true, 'label' => 'کارت‌های شیشه‌ای'],
                    'glass' => ['type' => 'glass', 'label' => 'تنظیماتِ نمای شیشه‌ای', 'hint' => 'روی ظاهرِ شیشه‌ایِ خودِ کارت‌ها اثر می‌گذارد — رنگ، شفافیت، بلور، عمق و رینگِ نورِ کارت‌ها را از این‌جا تنظیم می‌کنید.'],
                ],
            ],

            'faq' => [
                'label' => 'سؤالات متداول',
                'hint' => 'آکاردئونِ سؤال‌ها. خودِ سؤال‌ها این‌جا نوشته نمی‌شوند — از دسته‌بندی‌های FAQ خوانده می‌شوند؛ سایت همیشه همه‌ی دسته‌ها را با فیلترِ pill نشان می‌دهد.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'عنوانِ بخش'],
                    'intro' => ['type' => 'textarea', 'max' => 400, 'label' => 'توضیحِ زیرِ عنوان'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکسِ هیرو'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'subtitle' => ['type' => 'text', 'label' => 'زیرعنوان'],
                    // هر دو را کامپوننت بدونِ گارد `map` می‌کند — خالی‌بودنشان
                    // صفحه را می‌شکند، پس اجباری‌اند.
                    'lines' => ['type' => 'list', 'of' => 'textarea', 'required' => true, 'label' => 'خط‌های نوارِ روی عکس', 'hint' => 'هر عضوِ فهرست یک خط از متنِ روی نوارِ شیشه‌ای زیرِ عکس است.'],
                    'actions' => ['type' => 'list', 'of' => 'action', 'required' => true, 'max' => 2, 'label' => 'دکمه‌های فراخوان'],
                    'glass' => ['type' => 'glass', 'label' => 'تنظیماتِ نمای شیشه‌ای', 'hint' => 'روی نوارِ شیشه‌ایِ زیرِ عکسِ هیرو اثر می‌گذارد — رنگ، شفافیت، بلور، عمق و رینگِ نورِ همان نوار را از این‌جا تنظیم می‌کنید.'],
                ],
            ],

            'card_grid' => [
                'label' => 'شبکه‌ی کارت',
                'hint' => 'چند کارتِ عکس + عنوان + زیرنویس، با تونِ روشن/تیره و جهتِ چیدمان.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdCardGrid.jsx:19` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'عنوانِ بخش'],
                    'tone' => ['type' => 'select', 'options' => ['light', 'dark'], 'required' => true, 'label' => 'تمِ رنگیِ کارت‌ها', 'hint' => 'روشن یا تیره‌بودنِ ظاهرِ کارت‌ها.'],
                    'flow' => ['type' => 'select', 'options' => ['rtl', 'ltr'], 'required' => true, 'label' => 'جهتِ چیدمانِ ردیف', 'hint' => 'راست‌به‌چپ یا چپ‌به‌راست‌بودنِ چیدمانِ کارت‌ها در ردیف.'],
                    'cards' => ['type' => 'list', 'of' => 'card', 'required' => true, 'label' => 'کارت‌ها'],
                ],
            ],

            'media_text' => [
                'label' => 'عکس و متن',
                'hint' => 'عکس در یک سو و عنوان با چند پاراگراف در سوی دیگر.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // این نوع را هم `PdIntro.jsx:20` هم `SkStory.jsx:24`
                    // می‌خوانند؛ هر دو آن را روی `<section>` می‌گذارند.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکس'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true, 'label' => 'پاراگراف‌های متن', 'hint' => 'هر عضوِ فهرست یک پاراگراف است.'],
                    // اندیسِ اعضای فهرستِ paragraphs/runsِ فلت‌شده‌ی `body` که باید
                    // بولد شوند (`PdIntro.jsx:16`، `resolveBodyRuns` مصرف می‌کند) —
                    // اندیسِ کاراکتر نیست، اندیسِ «ران» است؛ با `captionEnBold` بالا
                    // یکی نیست.
                    'bodyStrong' => [
                        'type' => 'list', 'of' => 'number',
                        'label' => 'اندیس‌های بولدِ متن',
                        'hint' => 'اندیسِ بخش‌هایی از «پاراگراف‌های متن» که باید پررنگ نمایش داده شوند — عدد جایگاه است، نه خودِ متن.',
                    ],
                ],
            ],

            'flip_card' => [
                'label' => 'کارتِ برگردان',
                'hint' => 'یک کارتِ عکس که با کلیک برمی‌گردد و پاراگراف‌های پشتش را نشان می‌دهد.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdScale.jsx:19` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکس'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوانِ بخش'],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea', 'required' => true, 'label' => 'پاراگراف‌های پشتِ کارت'],
                ],
            ],

            'cta_band' => [
                'label' => 'نوارِ پایانی',
                'hint' => 'عکسِ تمام‌عرض با متن و یک دکمه روی آن.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `PdClosing.jsx:21` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکسِ زمینه'],
                    'alt' => ['type' => 'text', 'max' => 160, 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'عنوان'],
                    'lead' => ['type' => 'textarea', 'max' => 400, 'label' => 'پاراگرافِ مقدماتی'],
                    // `PdClosing.jsx:27` بدونِ گارد `action.href` را می‌خواند —
                    // نبودِ `action` استثنا پرت می‌کند و کلِ صفحه را سفید می‌کند.
                    'action' => ['type' => 'link', 'required' => true, 'label' => 'دکمه‌ی فراخوان'],
                    'fineprint' => ['type' => 'textarea', 'max' => 400, 'label' => 'متنِ ریزِ پابرگ', 'hint' => 'توضیحِ کوچک زیرِ دکمه، مثلِ شرایط یا یادداشتِ حقوقی.'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکسِ تمام‌عرض'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'captionEn' => ['type' => 'text', 'label' => 'کپشنِ لاتین', 'hint' => 'اختیاری؛ اگر پر شود زیرِ عنوان نمایش داده می‌شود.'],
                    'body' => ['type' => 'textarea', 'required' => true, 'label' => 'پاراگرافِ متن'],
                    'reveal' => ['type' => 'boolean', 'label' => 'نمایشِ تدریجی هنگامِ اسکرول', 'hint' => 'برای بلوکی که بالای صفحه است خاموش بگذارید — محتوایی که همین الان دیده می‌شود نباید دوباره محو-ظاهر شود.'],
                    'glass' => ['type' => 'glass', 'label' => 'تنظیماتِ نمای شیشه‌ای', 'hint' => 'روی نوارِ شیشه‌ایِ زیرِ عنوان اثر می‌گذارد — رنگ، شفافیت، بلور، عمق و رینگِ نورِ همان نوار را از این‌جا تنظیم می‌کنید.'],
                ],
            ],

            'stat_carousel' => [
                'label' => 'چرخ‌وفلکِ کارت',
                'hint' => 'کارت‌های عددی که افقی می‌چرخند: هر کارت یک عکس، یک عدد، یک برچسب، یک زیرنویسِ کوتاه و یک پاراگراف زیرِ عکس.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkGlance.jsx:18` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'cards' => ['type' => 'list', 'of' => 'stat', 'required' => true, 'label' => 'کارت‌های عددی'],
                ],
            ],

            'feature_cards' => [
                'label' => 'دو کارتِ محصول',
                'hint' => 'کارت‌های بزرگِ محصول؛ نوشته روی خودِ عکس می‌نشیند، پس رنگِ تیتر و زیرتیترِ هر کارت جدا انتخاب می‌شود.',
                'fields' => [
                    // anchor id اختیاری — توضیحِ کامل بالای `hero_actions`.
                    // `SkProducts.jsx:31` آن را روی `<section>` می‌گذارد.
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'cards' => ['type' => 'list', 'of' => 'feature', 'required' => true, 'label' => 'کارت‌های محصول'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'cards' => ['type' => 'list', 'of' => 'banner_card', 'required' => true, 'label' => 'کارت‌ها'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'cards' => ['type' => 'list', 'of' => 'flip_panel', 'required' => true, 'label' => 'کارت‌ها'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160, 'label' => 'عنوان'],
                    'cards' => ['type' => 'list', 'of' => 'card', 'required' => true, 'label' => 'کارت‌ها'],
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
                    'id' => ['type' => 'text', 'max' => 190, 'label' => 'شناسه‌ی لنگر (anchor)', 'hint' => 'برای لینک‌هایی مثلِ «/podium#services» که با کلیک به همین بخش اسکرول می‌کنند. تغییرِ این مقدار هر لینکی را که به مقدارِ قبلی اشاره دارد می‌شکند.'],
                    'image' => ['type' => 'image', 'required' => true, 'label' => 'عکس'],
                    'alt' => ['type' => 'text', 'label' => 'متن جایگزین عکس (alt)'],
                    'title' => ['type' => 'text', 'required' => true, 'label' => 'عنوان'],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true, 'label' => 'پاراگراف‌های متن', 'hint' => 'هر عضوِ فهرست یک پاراگراف است.'],
                    // اندیسِ اعضای فهرستِ paragraphs/runsِ فلت‌شده‌ی `body` که باید
                    // بولد شوند — دقیقاً همان قراردادِ `media_text.bodyStrong`
                    // بالا (`SkStory.jsx:41`، `resolveBodyRuns` مصرف می‌کند).
                    'bodyStrong' => [
                        'type' => 'list', 'of' => 'number',
                        'label' => 'اندیس‌های بولدِ متن',
                        'hint' => 'اندیسِ بخش‌هایی از «پاراگراف‌های متن» که باید پررنگ نمایش داده شوند — عدد جایگاه است، نه خودِ متن.',
                    ],
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

    /**
     * هر کلیدی که در هرجای اسکیما — فیلدِ سطحِ بالای یک نوع، یا عضوِ یک
     * SHAPE — با `type: image` اعلام شده. تنها منبعِ حقیقتِ کلیدهای مدیا
     * برای `PageBlockResource::resolveMedia()`؛ فهرستِ دستیِ جداگانه دقیقاً
     * همان واگرایی‌ای است که این متد را لازم کرد (تغییرِ نامِ `poster` به
     * `posterSrc` بی‌صدا از یک فهرستِ دستیِ جدا عقب افتاد).
     *
     * @return list<string>
     */
    public function imageKeys(): array
    {
        $keys = [];

        foreach ($this->all() as $definition) {
            foreach ($definition['fields'] as $name => $field) {
                if ($field['type'] === 'image') {
                    $keys[] = $name;
                }
            }
        }

        foreach (self::SHAPES as $members) {
            foreach ($members as $name => $member) {
                if ($member['type'] === 'image') {
                    $keys[] = $name;
                }
            }
        }

        return array_values(array_unique($keys));
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
                'glass' => $rules += $this->glassRules($key, $required),
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

            // هر عضوِ شکل اکنون همیشه آرایه است (`label` کنارِ `type` نگه
            // داشته می‌شود)، پس تشخیص یکسره روی خودِ `type` است — قبل از
            // افزودنِ `label`، «آرایه‌بودن» به‌تنهایی یعنی «select با
            // options»؛ دیگر نیست: `label`/`hint` روی هر عضوی نشسته‌اند.
            if ($member['type'] === 'list') {
                // عضوی از شکل که خودش فهرست است — مثلِ `flip_panel.paragraphs`
                // (هر کارت چند پاراگراف دارد). با فراخوانیِ بازگشتیِ همین متد
                // روی کلیدِ تودرتو، هم قاعده‌ی سطحِ آرایه و هم قاعده‌ی عضو را
                // می‌گیریم — بدونِ کپیِ منطق. عضوِ فهرست هرگز خودش اجباری نیست؛
                // اجباری‌بودنِ کلِ فهرستِ بیرونی (بالا) کافی است.
                $rules += $this->listRules($memberKey, $member, false);

                continue;
            }

            if ($member['type'] === 'select') {
                $rules[$memberKey] = ['nullable', 'string', Rule::in($this->selectOptions($member, "{$field['of']}.{$name}"))];

                continue;
            }

            if ($member['type'] === 'link') {
                // همان دو قاعده‌ی سطحِ بالای `link` (بالای همین فایل) —
                // بدونِ آن‌ها `.to` هیچ سقفی نداشت، برخلافِ `link` سطحِ بالا
                // که همیشه به ۲۰۴۸ محدود بوده.
                $rules[$memberKey] = ['nullable', 'array'];
                $rules[$memberKey.'.label'] = ['nullable', 'string', 'max:120'];
                $rules[$memberKey.'.to'] = ['nullable', 'string', 'max:2048'];

                continue;
            }

            $rules[$memberKey] = ['nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * کلیدهای مجازِ آبجکتِ `glass` — همان‌هایی که ادمین در ویرایشگرِ شیشه‌ی
     * بک‌آفیس می‌بیند (`variant` + هفت کنترل) و سایت به متغیرِ CSS ترجمه
     * می‌کند. تنها منبعِ این فهرست همین‌جاست.
     */
    private const GLASS_VARIANTS = ['clear', 'light', 'dark', 'control'];

    private const GLASS_COLOR_FIELDS = ['fill', 'rimSide', 'rimFlat'];

    private const GLASS_NUMBER_FIELDS = ['frost', 'depth', 'lightAngle', 'radius'];

    /**
     * پیش از این، `data.glass` فقط `['nullable', 'array']` بود — بدونِ قاعده‌ی
     * فرزند، لاراول نمی‌تواند کلیدِ ناشناخته را از آرایه حذف کند
     * (`excludeUnvalidatedArrayKeys`)، پس هر JSONای که ادمین می‌فرستاد
     * دقیقاً همان‌طور ذخیره می‌شد.
     */
    private function glassRules(string $key, string $required): array
    {
        $rules = [
            $key => [$required, 'array'],
            $key.'.variant' => ['nullable', 'string', Rule::in(self::GLASS_VARIANTS)],
        ];

        foreach (self::GLASS_COLOR_FIELDS as $field) {
            $rules[$key.'.'.$field] = ['nullable', 'string', 'max:255'];
        }

        foreach (self::GLASS_NUMBER_FIELDS as $field) {
            $rules[$key.'.'.$field] = ['nullable', 'numeric'];
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

<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * انتقالِ محتوایی که تا امروز در کدِ فرانت هاردکد بود، به دیتابیس.
 *
 * منبع دقیق (تا قبل از این تغییر):
 *   - دسته‌ها/سوالات → FrontEnd `src/features/support/data/supportData.js`
 *   - مگامنو        → FrontEnd `src/features/navbar/ui/SubComponent/NavMenu.jsx`
 *
 * هدف این است که بعد از وصل‌شدن فرانت به API، سایت **دقیقاً همان چیزی** را نشان
 * دهد که قبلاً نشان می‌داد — بدون هیچ رگرسیون بصری — ولی از این به بعد ادمین
 * بتواند در پنل تغییرش دهد.
 *
 * ⚠️ idempotent است (firstOrCreate روی نامک/عنوان)، پس اجرای دوباره چیزی را
 * تکراری نمی‌کند و محتوایی که ادمین بعداً ویرایش کرده را بازنویسی نمی‌کند.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFaq();
        $this->seedMenu();
    }

    private function seedFaq(): void
    {
        // ساختار دقیقاً از supportData.js — شامل زیرعنوان‌ها که در منوی پشتیبانی
        // زیر عنوان هر دسته نمایش داده می‌شوند.
        $categories = [
            [
                'slug' => 'why-elitezone',
                'title' => 'چرا الیت زون',
                'subtitle' => 'الیت زون چیست؟، تضمین موفقیت الیت زون، حجم پروژه ها با نرم افزار SAZE90, ...',
                'faqs' => [
                    [
                        'question' => 'الیت زون چیست؟',
                        'answer' => 'الیت زون (EliteZone) یک سامانه آنلاین در خصوص خرید مصالح ساختمانی استاندارد است که با استفاده از پتانسیل مهندسان و پیمانکاران برای راهنمایی به کارفرمایان، بازار بسیار بزرگی برای سوپلایرها (تامین کنندگان ممتاز) ایجاد می کند. این موضوع منجر به تامین مصالح ساختمانی با کیفیت بالا و قیمت رقابتی برای کارفرمایان، کاهش هزینه های بازاریابی برای سوپلایرها، و درآمد برای مهندسان و پیمانکاران می گردد.',
                    ],
                ],
            ],
            ['slug' => 'register', 'title' => 'ثبت نام و عملکرد', 'subtitle' => 'ثبت نام، ثبت سفارش، ...', 'faqs' => []],
            ['slug' => 'wallet', 'title' => 'کیف پول', 'subtitle' => 'عملکرد کیف پول، شارژ، نحوه خرید', 'faqs' => []],
            ['slug' => 'suppliers', 'title' => 'سوپلایرها', 'subtitle' => 'فولادکویر، آجر ساحل،...', 'faqs' => []],
            ['slug' => 'bank', 'title' => 'حساب دیجیتال پاسارگاد', 'subtitle' => 'افتتاح حساب، مزایا سیستم دیجیتال پاسارگاد', 'faqs' => []],
            // دسته‌ی «خرید» منبعِ آکاردئونِ سوالات متداولِ صفحه محصول/چک‌اوت است
            // (قبلاً در Questions2.jsx هاردکد بود). نامکِ purchase در فرانت شناخته‌شده است.
            [
                'slug' => 'purchase',
                'title' => 'خرید',
                'subtitle' => 'قیمت نهایی، حداقل سفارش، زمان تحویل',
                'faqs' => [
                    [
                        'question' => 'قیمت نهایی میلگرد چگونه محاسبه می‌شود و چه هزینه‌هایی را شامل می‌شود؟',
                        'answer' => 'قیمت پایه میلگرد + ۹٪ مالیات + هزینه بارگیری + کرایه و بیمه حمل.',
                    ],
                    [
                        'question' => 'حداقل مقدار سفارش و شرایط تخفیف حجمی به چه صورت است؟',
                        'answer' => 'حداقل سفارش ۲ تا ۵ تن است و برای سفارش‌های بالای ۲۰ تن تخفیف حجمی اعمال می‌شود.',
                    ],
                    [
                        'question' => 'زمان‌بندی آماده‌سازی و تحویل میلگرد پس از ثبت سفارش چگونه است و چه روش‌های حمل در دسترس است؟',
                        'answer' => 'حداکثر یک روز برای بارگیری و ۱ تا ۳ روز تا رسیدن کالا (بسته به مسافت).',
                    ],
                ],
            ],
        ];

        foreach ($categories as $order => $data) {
            $category = FaqCategory::firstOrCreate(
                [FaqCategory::SLUG => $data['slug']],
                [
                    FaqCategory::TITLE => $data['title'],
                    FaqCategory::SUBTITLE => $data['subtitle'],
                    FaqCategory::SORT_ORDER => $order,
                    FaqCategory::IS_ACTIVE => true,
                ]
            );

            foreach ($data['faqs'] as $faqOrder => $faq) {
                Faq::firstOrCreate(
                    [
                        Faq::FAQ_CATEGORY_ID => $category->getKey(),
                        Faq::QUESTION => $faq['question'],
                    ],
                    [
                        Faq::ANSWER => $faq['answer'],
                        Faq::CATEGORY => $data['title'],
                        Faq::SORT_ORDER => $faqOrder,
                        Faq::IS_ACTIVE => true,
                    ]
                );
            }
        }
    }

    private function seedMenu(): void
    {
        // ساختار دقیقاً از NavMenu.jsx. آیتم‌هایی که آنجا `action: ''` داشتند،
        // اینجا url = null می‌گیرند: یعنی «عنوانِ بدون لینک» — تا وقتی ادمین در
        // پنل آدرسشان را پر کند. این از لینکِ مرده صادقانه‌تر است.
        $menu = [
            [
                'title' => 'میلگرد', 'url' => '/rebar',
                'children' => [
                    // ستون اول در طراحی فعلی سرستون ندارد؛ با عنوان خالیِ معنادار
                    // نگه داشته می‌شود تا ادمین بتواند بعداً نامش را بگذارد.
                    ['title' => 'خرید و مشخصات', 'url' => null, 'children' => [
                        ['title' => 'خرید', 'url' => '/rebar', 'highlight' => true],
                        ['title' => 'شاخه', 'url' => null],
                        ['title' => 'کلاف', 'url' => null],
                        ['title' => 'سایزها', 'url' => null],
                        ['title' => 'گریدها', 'url' => null],
                        ['title' => 'حمل و نقل', 'url' => null],
                    ]],
                    ['title' => 'محصولات صنعتی', 'url' => null, 'children' => [
                        ['title' => 'پس سی وایر', 'url' => null],
                        ['title' => 'پس سی استرند', 'url' => null],
                        ['title' => 'فولاد کم کربن', 'url' => null],
                        ['title' => 'فولاد الکترود', 'url' => null],
                        ['title' => 'فولادهای B دار', 'url' => null],
                    ]],
                    ['title' => 'فرآیندها', 'url' => null, 'children' => [
                        ['title' => 'کنترل کیفیت', 'url' => null],
                        ['title' => 'بسته بندی', 'url' => null],
                        ['title' => 'بُرش و خَم', 'url' => null],
                        ['title' => 'جوشکاری', 'url' => null],
                    ]],
                    ['title' => 'لینک های سریع', 'url' => null, 'children' => [
                        ['title' => 'فروش', 'url' => null],
                        ['title' => 'پشتیبانی', 'url' => null],
                        ['title' => 'آمار و ارقام', 'url' => null],
                        ['title' => 'مسئولیت اجتماعی', 'url' => null],
                    ]],
                ],
            ],
            ['title' => 'روش‌های پرداخت', 'url' => null, 'children' => []],
            ['title' => 'همه چیز آنلاین', 'url' => null, 'children' => []],
            [
                'title' => 'فولاد کویر', 'url' => null,
                'children' => [
                    ['title' => 'اولین در ایران', 'url' => null, 'children' => [
                        ['title' => 'محصولات', 'url' => null],
                        ['title' => 'حمل و نقل', 'url' => null],
                        ['title' => 'فروش', 'url' => null],
                        ['title' => 'پشتیبانی', 'url' => null],
                        ['title' => 'استانداردها', 'url' => null],
                        ['title' => 'آمار و ارقام', 'url' => null],
                    ]],
                    ['title' => 'مطمئن بسازید!', 'url' => null, 'children' => [
                        ['title' => 'کنترل کیفیت', 'url' => null],
                        ['title' => 'بسته بندی', 'url' => null],
                        ['title' => 'افتخارات', 'url' => null],
                        ['title' => 'دیدگاه', 'url' => null],
                        ['title' => 'مسئولیت اجتماعی', 'url' => null],
                    ]],
                    ['title' => 'سازمان', 'url' => null, 'children' => [
                        ['title' => 'بنیانگذاران', 'url' => null],
                        ['title' => 'دفاتر', 'url' => null],
                        ['title' => 'در محل مجتمع', 'url' => null],
                        ['title' => 'شبکه های اجتماعی', 'url' => null],
                    ]],
                ],
            ],
            [
                'title' => 'کنجکاوید', 'url' => null,
                'children' => [
                    ['title' => 'نقش شما', 'url' => null, 'children' => [
                        ['title' => 'دیـدگاه اِلیـت زون', 'url' => null],
                        ['title' => 'مهندس', 'url' => null],
                        ['title' => 'پیمانکار', 'url' => null],
                        ['title' => 'سوپلایر', 'url' => null],
                        ['title' => 'کارفرما', 'url' => null],
                    ]],
                    // این ستون حالا لینک‌های واقعیِ محتوایی دارد که در همین نشست ساخته شد
                    ['title' => 'راهنما', 'url' => null, 'children' => [
                        ['title' => 'سوالات متداول', 'url' => '/faq'],
                        ['title' => 'مجله', 'url' => null],
                        ['title' => 'تاریخچه', 'url' => null],
                        ['title' => 'هویت برند', 'url' => null],
                        ['title' => 'تماس با ما', 'url' => null],
                    ]],
                    ['title' => 'قوانین', 'url' => null, 'children' => [
                        ['title' => 'شرایط الیت زون', 'url' => null],
                        ['title' => 'حریم خصوصی', 'url' => null],
                        ['title' => 'فرآیند اجرایی', 'url' => null],
                    ]],
                ],
            ],
        ];

        foreach ($menu as $order => $root) {
            $this->createMenuItem($root, null, $order, MenuItem::LOCATION_HEADER);
        }

        $this->seedFooterMenu();
    }

    /**
     * ستون‌های لینکِ فوتر — دقیقاً از `NAV_COLUMNS` در Footer.jsx.
     *
     * ساختار دو سطحی است: عنوانِ ستون (url = null، فقط تیتر) و لینک‌های داخلش.
     * ستون «تماس با ما» اینجا نیست چون از تنظیمات سیستم می‌آید، نه از منو.
     */
    private function seedFooterMenu(): void
    {
        $columns = [
            [
                'title' => 'دسترسی سریع', 'url' => null,
                'children' => [
                    ['title' => 'صفحه اصلی', 'url' => '/'],
                    ['title' => 'خرید میلگرد', 'url' => '/rebar'],
                    ['title' => 'سبد خرید', 'url' => '/order'],
                ],
            ],
            [
                'title' => 'حساب کاربری', 'url' => null,
                'children' => [
                    ['title' => 'پنل کاربری', 'url' => '/profile'],
                    ['title' => 'سفارش‌های من', 'url' => '/profile/orders/self/current'],
                    ['title' => 'کیف پول', 'url' => '/profile/finance/wallet'],
                    ['title' => 'آدرس‌های من', 'url' => '/profile/addresses'],
                ],
            ],
            [
                'title' => 'راهنما و پشتیبانی', 'url' => null,
                'children' => [
                    ['title' => 'تیکت پشتیبانی', 'url' => '/profile/support'],
                    ['title' => 'علاقه‌مندی‌ها', 'url' => '/profile/my_items'],
                    ['title' => 'سوالات متداول', 'url' => '/faq'],
                    ['title' => 'درباره ما', 'url' => '/p/about'],
                ],
            ],
        ];

        foreach ($columns as $order => $column) {
            $this->createMenuItem($column, null, $order, MenuItem::LOCATION_FOOTER);
        }
    }

    private function createMenuItem(array $data, ?int $parentId, int $order, string $location): void
    {
        // location هم بخشی از کلیدِ یکتاست: «راهنما» در مگامنو و «راهنما و پشتیبانی»
        // در فوتر دو رکورد جدا هستند و نباید یکدیگر را بازنویسی کنند.
        $item = MenuItem::firstOrCreate(
            [
                MenuItem::LOCATION => $location,
                MenuItem::PARENT_ID => $parentId,
                MenuItem::TITLE => $data['title'],
            ],
            [
                MenuItem::URL => $data['url'] ?? null,
                MenuItem::IS_HIGHLIGHTED => $data['highlight'] ?? false,
                MenuItem::SORT_ORDER => $order,
                MenuItem::IS_ACTIVE => true,
            ]
        );

        foreach ($data['children'] ?? [] as $childOrder => $child) {
            $this->createMenuItem($child, $item->getKey(), $childOrder, $location);
        }
    }
}

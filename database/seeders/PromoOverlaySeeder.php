<?php

namespace Database\Seeders;

use App\Models\PromoOverlay;
use Illuminate\Database\Seeder;

/**
 * سه اورلیِ ترغیبِ سایت که تا امروز کامپوننتِ هاردکدِ React بودند.
 *
 * idempotent است (`firstOrCreate` روی `key`): اجرای دوباره چیزی را بازنویسی
 * نمی‌کند، پس ویرایش‌های ادمین با هر بار seed از بین نمی‌رود — همان قاعده‌ای که
 * `SiteContentSeeder` دارد.
 *
 * تصویرها در `storage/app/public/promo-overlays/` هستند (از ریپوی فرانت‌اند
 * کپی شده‌اند، چون تا قبل از این ماژول بخشی از باندلِ React بودند). اگر فایلی
 * سرِ جایش نباشد مقدارِ `image` روی `null` می‌ماند و اورلی **بدونِ تصویر** رندر
 * می‌شود — نه تصویرِ شکسته. ادمین بعداً می‌تواند از پنل تصویر را عوض کند.
 *
 * ⚠️ svg عمداً نیست: فایل از دیسکِ public و روی دامنه‌ی خودمان سرو می‌شود و
 * SVGِ حاوی <script> یعنی XSSِ ذخیره‌شده (همان قاعده‌ای که
 * `StorePromoOverlayRequest` هم اعمال می‌کند).
 */
class PromoOverlaySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->overlays() as $overlay) {
            PromoOverlay::firstOrCreate(
                [PromoOverlay::KEY => $overlay[PromoOverlay::KEY]],
                $overlay
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function overlays(): array
    {
        return [
            // ── ۱) مهمان → خوش‌آمدگویی ───────────────────────────────────────
            [
                PromoOverlay::KEY => PromoOverlay::KEY_WELCOME,
                PromoOverlay::TITLE => 'به الیت زون خوش آمدید.',
                PromoOverlay::BODY => 'برای مشاهده و دریافت امتیازات و تخفیفات ویژه ورود | ثبت نام کنید.',
                PromoOverlay::IMAGE => 'promo-overlays/welcome.png',
                PromoOverlay::BUTTONS => [
                    ['label' => 'ورود | ثبت نام', 'style' => 'primary', 'action' => PromoOverlay::ACTION_AUTH, 'value' => null],
                    ['label' => 'خرید بدون تخفیف', 'style' => 'secondary', 'action' => PromoOverlay::ACTION_DISMISS, 'value' => null],
                    ['label' => 'اطلاعات بیشتر', 'style' => 'link', 'action' => PromoOverlay::ACTION_MORE_INFO, 'value' => 'employer'],
                ],
                PromoOverlay::AUDIENCE => [
                    'op' => 'and',
                    'rules' => [
                        ['type' => PromoOverlay::RULE_AUTH_STATE, 'value' => 'guest'],
                    ],
                ],
                // اورلیِ خوش‌آمد تاریخاً فقط در صفحهٔ خرید میلگرد بود
                PromoOverlay::ROUTES => ['include' => ['/rebar*'], 'exclude' => []],
                PromoOverlay::DELAY_SECONDS => 30,
                PromoOverlay::FREQUENCY => PromoOverlay::FREQ_DAY,
                PromoOverlay::PRIORITY => 10,
                PromoOverlay::IS_ACTIVE => true,
            ],

            // ── ۲) واردشده بدون نقش → تعیین نقش ──────────────────────────────
            [
                PromoOverlay::KEY => PromoOverlay::KEY_SELECT_ROLE,
                PromoOverlay::TITLE => '«در الیت زون، نقش شما محور حرکت ماست»',
                PromoOverlay::BODY => 'برای مشاهده و دریافت امتیازات و تخفیفات ویژه نقش خود را در الیت زون مشخص کنید.',
                PromoOverlay::IMAGE => 'promo-overlays/select-role.jpg',
                PromoOverlay::BUTTONS => [
                    ['label' => 'تعیین نقش', 'style' => 'primary', 'action' => PromoOverlay::ACTION_NAVIGATE, 'value' => '/role'],
                    ['label' => 'خرید بدون تخفیف', 'style' => 'secondary', 'action' => PromoOverlay::ACTION_DISMISS, 'value' => null],
                    ['label' => 'اطلاعات بیشتر', 'style' => 'link', 'action' => PromoOverlay::ACTION_MORE_INFO, 'value' => 'employer'],
                ],
                PromoOverlay::AUDIENCE => [
                    'op' => 'and',
                    'rules' => [
                        ['type' => PromoOverlay::RULE_AUTH_STATE, 'value' => 'authenticated'],
                        ['type' => PromoOverlay::RULE_HAS_ROLE, 'value' => false],
                    ],
                ],
                // همه‌جای سایت، به‌جز صفحاتی که نمایشش بی‌معنا/مزاحم است
                PromoOverlay::ROUTES => ['include' => ['*'], 'exclude' => ['/role*', '/auth*', '/admin*']],
                PromoOverlay::DELAY_SECONDS => 30,
                PromoOverlay::FREQUENCY => PromoOverlay::FREQ_DAY,
                // مقدم بر «تعیین الیت یار»: تا نقش مشخص نشده، پرسیدنِ الیت یار
                // بی‌معناست.
                PromoOverlay::PRIORITY => 20,
                PromoOverlay::IS_ACTIVE => true,
            ],

            // ── ۳) کارفرمای بدون الیت یار → تعیین الیت یار ────────────────────
            [
                PromoOverlay::KEY => PromoOverlay::KEY_SELECT_ELITE_YAR,
                PromoOverlay::TITLE => '«الیت یاران، روشنگر راه کارفرمایان»',
                PromoOverlay::BODY => 'برای مشاهده و دریافت امتیازات و تخفیفات ویژه الیت یار خود را تعیین کنید.',
                PromoOverlay::IMAGE => 'promo-overlays/select-elite-yar.jpg',
                PromoOverlay::BUTTONS => [
                    ['label' => 'تعیین الیت یار', 'style' => 'primary', 'action' => PromoOverlay::ACTION_NAVIGATE, 'value' => '/profile/employers'],
                    ['label' => 'خرید بدون تخفیف', 'style' => 'secondary', 'action' => PromoOverlay::ACTION_DISMISS, 'value' => null],
                    ['label' => 'اطلاعات بیشتر', 'style' => 'link', 'action' => PromoOverlay::ACTION_MORE_INFO, 'value' => 'employer'],
                ],
                PromoOverlay::AUDIENCE => [
                    'op' => 'and',
                    'rules' => [
                        ['type' => PromoOverlay::RULE_AUTH_STATE, 'value' => 'authenticated'],
                        ['type' => PromoOverlay::RULE_HAS_ROLE, 'value' => true],
                        // فقط کارفرمای خالص — «الیت یار و کارفرما» خودش الیت یار است
                        ['type' => PromoOverlay::RULE_PERSONA, 'value' => ['employer']],
                        ['type' => PromoOverlay::RULE_HAS_ELITE_YAR, 'value' => false],
                    ],
                ],
                PromoOverlay::ROUTES => ['include' => ['*'], 'exclude' => ['/role*', '/auth*', '/admin*']],
                PromoOverlay::DELAY_SECONDS => 30,
                PromoOverlay::FREQUENCY => PromoOverlay::FREQ_DAY,
                PromoOverlay::PRIORITY => 30,
                PromoOverlay::IS_ACTIVE => true,
            ],
        ];
    }
}

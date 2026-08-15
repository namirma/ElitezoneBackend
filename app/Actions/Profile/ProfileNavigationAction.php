<?php

namespace App\Actions\Profile;

use App\Models\ProfileSearchHistory;
use Illuminate\Support\Facades\Auth;

class ProfileNavigationAction
{
    /**
     * لیست جامع تمام منوهای اصلی و زیرمنوهای پنل کاربری (پروفایل)
     * این لیست مرجع اصلی جستجوی زنده است.
     *
     * ⚠️ هر `url` باید **دقیقاً** یکی از روت‌های React Router در
     * `EliteZone_FrontEnd_Phase2/src/App.jsx` باشد. این آرایه مستقیم به
     * `navigate()` می‌رود، پس آدرسِ نزدیک‌اما-نادرست یعنی کاربر روی لینکِ خودِ
     * ما به ۴۰۴ می‌رسد. سه تله‌ی تکرارشونده:
     *   - جداکننده `_` است نه `-` (`my_items`, `account_information`)
     *   - بخش مالی `finance` است نه `financial`؛ پشتیبانی `support` است نه `tickets`
     *   - تب‌ها تودرتواند: `/profile/{section}/{tab}` نه `/profile/{tab}`
     *
     * `EmittedUrlsMatchSpaRoutesTest` کلِ این آرایه را جارو می‌کند.
     *
     * ترتیب معنادار است: `getInitialData()` فقط پنج آیتمِ اول را برمی‌گرداند.
     */
    private array $quickAccessItems = [
        // --- منوی اطلاعات حساب و زیرمنوها ---
        ['title' => 'اطلاعات حساب کاربری', 'url' => '/profile/account_information', 'keywords' => ['حساب', 'کاربری', 'مشخصات', 'پروفایل']],
        ['title' => 'ویرایش پروفایل حقیقی', 'url' => '/profile/account_information/personal', 'keywords' => ['حقیقی', 'فردی', 'مشخصات', 'ویرایش']],
        ['title' => 'ویرایش پروفایل حقوقی', 'url' => '/profile/account_information/legal', 'keywords' => ['حقوقی', 'شرکت', 'سازمان', 'ویرایش']],
        ['title' => 'اطلاعات تحصیلی', 'url' => '/profile/professional/education', 'keywords' => ['تحصیل', 'دانشگاه', 'مدرک']],
        ['title' => 'اطلاعات شغلی', 'url' => '/profile/professional/job', 'keywords' => ['شغل', 'کار', 'شرکت']],

        // --- منوی سفارشات و زیرمنوها ---
        ['title' => 'سفارش‌های جاری', 'url' => '/profile/orders/self/current', 'keywords' => ['سفارش', 'جاری', 'خرید', 'پیگیری']],
        ['title' => 'تاریخچه تمام سفارش‌ها', 'url' => '/profile/orders', 'keywords' => ['سفارش', 'آرشیو', 'تاریخچه', 'قدیمی']],
        ['title' => 'سفارش‌های تحویل‌شده', 'url' => '/profile/orders/self/delivered', 'keywords' => ['سفارش', 'تحویل', 'دریافت', 'تکمیل']],
        ['title' => 'سفارش‌های لغو شده', 'url' => '/profile/orders/self/cancelled', 'keywords' => ['سفارش', 'لغو', 'کنسلی', 'ابطال']],

        // --- منوی پیام‌ها و پشتیبانی ---
        ['title' => 'پیغام‌ها و اعلان‌ها', 'url' => '/profile/messages', 'keywords' => ['پیام', 'نوتیفیکیشن', 'اعلان', 'پیغام']],
        ['title' => 'تیکت‌های پشتیبانی', 'url' => '/profile/support', 'keywords' => ['تیکت', 'پشتیبانی', 'ارسال پیام', 'سوال']],

        // --- منوی بخش مالی و زیرمنوها ---
        ['title' => 'مدیریت مالی و فاکتورها', 'url' => '/profile/finance', 'keywords' => ['مالی', 'تراکنش', 'پرداخت', 'فاکتور']],
        ['title' => 'کیف پول (مشاهده موجودی)', 'url' => '/profile/finance/wallet', 'keywords' => ['کیف', 'پول', 'شارژ', 'موجودی']],
        // تراکنش‌ها صفحه‌ی جدا ندارند و داخل همان تبِ کیف پول‌اند.
        ['title' => 'تراکنش‌های کیف پول', 'url' => '/profile/finance/wallet', 'keywords' => ['تراکنش', 'تاریخچه مالی', 'واریز', 'برداشت']],
        ['title' => 'شماره حساب‌های بانکی', 'url' => '/profile/finance/accounts_number', 'keywords' => ['حساب', 'شبا', 'بانک', 'کارت']],

        // --- منوی آدرس‌ها ---
        // مسیر جداگانه‌ی `create` وجود ندارد؛ افزودن آدرس روی همین صفحه است.
        ['title' => 'آدرس‌ها و نشانی‌ها', 'url' => '/profile/addresses', 'keywords' => ['آدرس', 'لوکیشن', 'نشانی', 'جدید', 'افزودن']],

        // --- سایر منوها ---
        ['title' => 'آیتم‌های من (علاقه‌مندی‌ها)', 'url' => '/profile/my_items', 'keywords' => ['آیتم', 'لیست', 'علاقه‌مندی']],
        ['title' => 'علاقه‌مندی‌های ذخیره‌شده', 'url' => '/profile/my_items/saved', 'keywords' => ['ذخیره', 'علاقه‌مندی', 'نشان‌شده']],
        ['title' => 'کارفرمایان و الیت یاران', 'url' => '/profile/employers', 'keywords' => ['کارفرما', 'الیت یار', 'همکاری', 'دعوت']],
        ['title' => 'تنظیمات حساب', 'url' => '/profile/account_settings', 'keywords' => ['تنظیمات', 'رمز', 'موبایل', 'امنیت']],
        ['title' => 'توافق‌نامه‌ها', 'url' => '/profile/agreements', 'keywords' => ['توافق', 'قرارداد', 'شرایط', 'قوانین']],
    ];

    /**
     * دریافت اطلاعات اولیه (زمانی که کاربر فقط روی باکس سرچ کلیک کرده است)
     */
    public function getInitialData(): array
    {
        $userId = Auth::id();

        // دریافت ۵ جستجوی اخیر کاربر از تاریخچه
        $recentSearches = ProfileSearchHistory::where('user_id', $userId)
            ->latest()
            ->pluck('keyword')
            ->unique()
            ->take(5)
            ->values();

        // فقط ۵ آیتم اول لیست جهت نمایش پیش‌فرض در بخش دسترسی سریع
        $quickAccessPreview = array_slice($this->quickAccessItems, 0, 5);

        return [
            'recent_searches' => $recentSearches,
            'quick_access' => $quickAccessPreview, // محبوب‌ها کاملاً حذف شدند
        ];
    }

    /**
     * جستجوی زنده در زمان تایپ کاربر روی تمام کلیدها و زیرمنوها
     */
    public function search(string $keyword): array
    {
        $keyword = mb_strtolower(trim($keyword));

        $filtered = array_filter($this->quickAccessItems, function ($item) use ($keyword) {
            // بررسی مطابقت با عنوان اصلی منو
            if (str_contains(mb_strtolower($item['title']), $keyword)) {
                return true;
            }
            // بررسی مطابقت با کلمات کلیدی مترادف و فرعی
            foreach ($item['keywords'] as $kw) {
                if (str_contains($kw, $keyword)) {
                    return true;
                }
            }

            return false;
        });

        return array_values($filtered);
    }
}

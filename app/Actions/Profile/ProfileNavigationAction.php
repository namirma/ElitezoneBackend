<?php

namespace App\Actions\Profile;

use App\Models\ProfileSearchHistory;
use Illuminate\Support\Facades\Auth;

class ProfileNavigationAction
{
    /**
     * لیست جامع تمام منوهای اصلی و زیرمنوهای پنل کاربری (پروفایل)
     * این لیست مرجع اصلی جستجوی زنده است.
     */
    private array $quickAccessItems = [
        // --- منوی اطلاعات حساب و زیرمنوها ---
        ['title' => 'اطلاعات حساب کاربری', 'url' => '/profile/account-info', 'keywords' => ['حساب', 'کاربری', 'مشخصات', 'پروفایل']],
        ['title' => 'ویرایش پروفایل حقیقی', 'url' => '/profile/real-profile', 'keywords' => ['حقیقی', 'فردی', 'مشخصات', 'ویرایش']],
        ['title' => 'ویرایش پروفایل حقوقی', 'url' => '/profile/legal-profile', 'keywords' => ['حقوقی', 'شرکت', 'سازمان', 'ویرایش']],
        ['title' => 'اطلاعات تحصیلی', 'url' => '/profile/education', 'keywords' => ['تحصیل', 'دانشگاه', 'مدرک']],
        ['title' => 'اطلاعات شغلی', 'url' => '/profile/job', 'keywords' => ['شغل', 'کار', 'شرکت']],

        // --- منوی سفارشات و زیرمنوها ---
        ['title' => 'سفارش‌های جاری', 'url' => '/profile/orders/active', 'keywords' => ['سفارش', 'جاری', 'خرید', 'پیگیری']],
        ['title' => 'تاریخچه تمام سفارش‌ها', 'url' => '/profile/orders/history', 'keywords' => ['سفارش', 'آرشیو', 'تاریخچه', 'قدیمی']],
        ['title' => 'سفارش‌های لغو شده', 'url' => '/profile/orders/cancelled', 'keywords' => ['سفارش', 'لغو', 'کنسلی', 'ابطال']],

        // --- منوی پیام‌ها و پشتیبانی ---
        ['title' => 'پیغام‌ها و اعلان‌ها', 'url' => '/profile/messages', 'keywords' => ['پیام', 'نوتیفیکیشن', 'اعلان', 'پیغام']],
        ['title' => 'تیکت‌های پشتیبانی', 'url' => '/profile/tickets', 'keywords' => ['تیکت', 'پشتیبانی', 'ارسال پیام', 'سوال']],

        // --- منوی بخش مالی و زیرمنوها ---
        ['title' => 'مدیریت مالی و فاکتورها', 'url' => '/profile/financial', 'keywords' => ['مالی', 'تراکنش', 'پرداخت', 'فاکتور']],
        ['title' => 'کیف پول (مشاهده موجودی)', 'url' => '/profile/wallet', 'keywords' => ['کیف', 'پول', 'شارژ', 'موجودی']],
        ['title' => 'تراکنش‌های کیف پول', 'url' => '/profile/wallet/transactions', 'keywords' => ['تراکنش', 'تاریخچه مالی', 'واریز', 'برداشت']],

        // --- منوی آدرس‌ها ---
        ['title' => 'آدرس‌ها و نشانی‌ها', 'url' => '/profile/addresses', 'keywords' => ['آدرس', 'لوکیشن', 'نشانی']],
        ['title' => 'ثبت آدرس جدید', 'url' => '/profile/addresses/create', 'keywords' => ['آدرس', 'جدید', 'افزودن', 'نشانی']],

        // --- سایر منوها ---
        ['title' => 'آیتم‌های من (علاقه‌مندی‌ها)', 'url' => '/profile/my-items', 'keywords' => ['آیتم', 'لیست', 'علاقه‌مندی']],
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

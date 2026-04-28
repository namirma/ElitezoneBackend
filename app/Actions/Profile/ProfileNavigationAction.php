<?php

namespace App\Actions\Profile;

use App\Models\ProfileSearchHistory;
use Illuminate\Support\Facades\Auth;

class ProfileNavigationAction
{
    private array $quickAccessItems = [
        ['title' => 'اطلاعات حساب کاربری', 'url' => '/profile/account-info', 'keywords' => ['حساب', 'کاربری', 'مشخصات', 'پروفایل']],
        ['title' => 'اطلاعات تحصیلی',     'url' => '/profile/education',    'keywords' => ['تحصیل', 'دانشگاه', 'مدرک']],
        ['title' => 'اطلاعات شغلی',       'url' => '/profile/job',          'keywords' => ['شغل', 'کار', 'شرکت']],
        ['title' => 'سفارش های جاری',     'url' => '/profile/orders/active','keywords' => ['سفارش', 'جاری', 'خرید', 'پیگیری']],
        ['title' => 'پیغام ها',           'url' => '/profile/messages',     'keywords' => ['پیام', 'تیکت', 'پشتیبانی', 'پیغام']],
        ['title' => 'مدیریت مالی',        'url' => '/profile/financial',    'keywords' => ['مالی', 'تراکنش', 'پرداخت']],
        ['title' => 'آدرس ها',            'url' => '/profile/addresses',    'keywords' => ['آدرس', 'لوکیشن', 'نشانی']],
        ['title' => 'کیف پول',            'url' => '/profile/wallet',       'keywords' => ['کیف', 'پول', 'شارژ', 'موجودی']],
        ['title' => 'آیتم های من',        'url' => '/profile/my-items',     'keywords' => ['آیتم', 'لیست']],
    ];

    public function getInitialData(): array
    {
        $userId = Auth::id();

        $recentSearches = ProfileSearchHistory::where('user_id', $userId)
            ->latest()
            ->pluck('keyword')
            ->unique()
            ->take(5)
            ->values();

        $popularSearches = ['مدیریت مالی', 'آدرس ها', 'آیتم های من', 'پیغام ها', 'سفارش ها', 'کیف پول'];

        $quickAccess = array_slice($this->quickAccessItems, 0, 5);

        return [
            'recent_searches'  => $recentSearches,
            'popular_searches' => $popularSearches,
            'quick_access'     => $quickAccess,
        ];
    }

    public function search(string $keyword): array
    {
        $keyword = mb_strtolower(trim($keyword));

        $filtered = array_filter($this->quickAccessItems, function ($item) use ($keyword) {
            if (str_contains(mb_strtolower($item['title']), $keyword)) {
                return true;
            }
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

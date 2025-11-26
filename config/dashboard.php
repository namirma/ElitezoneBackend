<?php

return [
    'modules' => [
        'account_info' => [
            'title' => 'اطلاعات حساب کاربری',
            'icon'  => 'user',
            'link'  => '/profile/account',
        ],
        'education' => [
            'title' => 'اطلاعات تحصیلی',
            'icon'  => 'academic-cap',
            'link'  => '/profile/education',
        ],
        'job' => [
            'title' => 'اطلاعات شغلی',
            'icon'  => 'briefcase',
            'link'  => '/profile/job-experiences',
        ],
        'orders' => [
            'title' => 'سفارش‌ها',
            'icon'  => 'truck',
            'link'  => '/profile/orders',
        ],
        'messages' => [
            'title' => 'پیغام‌ها',
            'icon'  => 'mail',
            'link'  => '/profile/messages',
        ],
        'wallet' => [
            'title' => 'مدیریت مالی',
            'icon'  => 'credit-card',
            'link'  => '/profile/wallet',
        ],
        'my_items' => [
            'title' => 'آیتم‌های من',
            'icon'  => 'archive',
            'link'  => '/profile/wishlists',
        ],
        'addresses' => [
            'title' => 'آدرس‌ها',
            'icon'  => 'location-marker',
            'link'  => '/profile/addresses',
        ],
        'relations' => [
            'title' => 'کارفرمایان',
            'icon'  => 'users',
            'link'  => '/profile/consultants',
        ],
    ],

    'defaults' => ['account_info', 'orders', 'wallet', 'messages', 'job'],
];

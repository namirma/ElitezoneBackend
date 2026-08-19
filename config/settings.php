<?php

return [
    /*
    |--------------------------------------------------------------------------
    | کلیدهای مجاز تنظیمات سایت (پنل ادمین — PUT /admin/settings)
    |--------------------------------------------------------------------------
    | هر کلیدی خارج از این لیست 422 می‌گیرد تا غلط تایپی، کلید زامبی نسازد.
    | برای افزودن کلید جدید، همین‌جا اضافه کنید (و به فرانت اطلاع دهید).
    */
    'allowed_keys' => [
        'site_title',
        'site_description',
        'support_phone',
        'support_email',
        'support_hours',
        'company_address',
        'instagram_url',
        'telegram_url',
        'linkedin_url',
        'whatsapp_number',
        'about_short',
        'footer_text',
        'maintenance_banner',
        'homepage_announcement',
        'min_app_version',

        /*
        | پارامترهای مسیر فروش (سند بازطراحی، بند ۲.۱).
        |
        | ⚠️ این شش کلید عمداً **در `public_keys` نیستند** و نباید اضافه شوند —
        | داخلی‌اند. پیش‌فرض‌هایشان در `config/sales.php` است و تنها راهِ
        | خواندنشان `App\Services\Sales\SalesSettings` است.
        */
        'shaparak_value',
        'reserve_value',
        'park_time_minutes',
        'reserve_grace_hours',
        'delivery_deadline_hours',
        'max_overweight_percent',
        'coupon_visibility',
        'reserve_warning_threshold_minutes',
    ],

    /*
    |--------------------------------------------------------------------------
    | کلیدهای قابل نمایش عمومی (GET /settings/public)
    |--------------------------------------------------------------------------
    | زیرمجموعه allowed_keys که بدون auth به فرانت داده می‌شود — کلید حساس
    | هرگز اینجا اضافه نشود.
    */
    'public_keys' => [
        'site_title',
        'site_description',
        'support_phone',
        'support_email',
        'support_hours',
        'company_address',
        'instagram_url',
        'telegram_url',
        'linkedin_url',
        'whatsapp_number',
        'about_short',
        'footer_text',
        'maintenance_banner',
        'homepage_announcement',
        'min_app_version',
    ],
];

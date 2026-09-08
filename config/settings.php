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
        // پیام‌رسان‌های داخلی — فوتر برای هرکدام آیکونِ اختصاصی دارد و کلیدِ
        // پرنشده اصلاً رندر نمی‌شود.
        'eitaa_url',
        'rubika_url',
        'bale_url',
        'about_short',
        'footer_text',
        'maintenance_banner',
        'homepage_announcement',
        'min_app_version',
        // متنِ نوارِ هشدارِ فیلترشکن.
        //
        // ⚠️ استثنای قاعده‌ی «خالی = مخفی»: تصمیمِ نمایش را کد می‌گیرد (IP غیرایرانی)،
        // نه ادمین. خالی‌بودنش نوار را حذف نمی‌کند، متنِ پیش‌فرضِ سایت را نگه می‌دارد —
        // وگرنه یک قابلیتِ زنده در روزِ استقرار بی‌صدا ناپدید می‌شد.
        'vpn_warning_text',
        // نمادهای اعتماد فوتر — تصویر + لینکِ مرجع. تا وقتی پر نشوند، فوتر آن
        // نماد را اصلاً نشان نمی‌دهد (به‌جای کادرِ placeholder).
        'enamad_image_url',
        'enamad_link',
        'samandehi_image_url',
        'samandehi_link',
        // مبالغ پیشنهادی شارژ کیف پول — فهرست اعداد ریالی جداشده با کاما.
        // خالی/نامعتبر = هیچ دکمه‌ی پیشنهادی نمایش داده نمی‌شود و کاربر مبلغ را
        // دستی وارد می‌کند (قاعده‌ی صداقت: عددِ جعلی نشان نده).
        'wallet_preset_amounts',

        /*
        | پارامترهای مسیر فروش (سند بازطراحی، بند ۲.۱).
        |
        | ⚠️ این کلیدها عمداً **در `public_keys` نیستند** و نباید اضافه شوند —
        | داخلی‌اند. پیش‌فرض‌هایشان در `config/sales.php` است و تنها راهِ
        | خواندنشان `App\Services\Sales\SalesSettings` است.
        */
        'shaparak_value',
        'reserve_value',
        'park_time_minutes',
        'reserve_grace_hours',
        'delivery_deadline_hours',
        'max_overweight_percent',
        'overweight_response_hours',
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
        // پیام‌رسان‌های داخلی — فوتر برای هرکدام آیکونِ اختصاصی دارد و کلیدِ
        // پرنشده اصلاً رندر نمی‌شود.
        'eitaa_url',
        'rubika_url',
        'bale_url',
        'about_short',
        'footer_text',
        'maintenance_banner',
        'homepage_announcement',
        'min_app_version',
        // متنِ نوارِ هشدارِ فیلترشکن.
        //
        // ⚠️ استثنای قاعده‌ی «خالی = مخفی»: تصمیمِ نمایش را کد می‌گیرد (IP غیرایرانی)،
        // نه ادمین. خالی‌بودنش نوار را حذف نمی‌کند، متنِ پیش‌فرضِ سایت را نگه می‌دارد —
        // وگرنه یک قابلیتِ زنده در روزِ استقرار بی‌صدا ناپدید می‌شد.
        'vpn_warning_text',
        // نمادهای اعتماد فوتر — تصویر + لینکِ مرجع. تا وقتی پر نشوند، فوتر آن
        // نماد را اصلاً نشان نمی‌دهد (به‌جای کادرِ placeholder).
        'enamad_image_url',
        'enamad_link',
        'samandehi_image_url',
        'samandehi_link',
        // مبالغ پیشنهادی شارژ کیف پول — فهرست اعداد ریالی جداشده با کاما.
        // خالی/نامعتبر = هیچ دکمه‌ی پیشنهادی نمایش داده نمی‌شود و کاربر مبلغ را
        // دستی وارد می‌کند (قاعده‌ی صداقت: عددِ جعلی نشان نده).
        'wallet_preset_amounts',
    ],
];

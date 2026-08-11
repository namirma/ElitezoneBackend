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

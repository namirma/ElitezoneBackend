<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        'https://elitezone.me',
        'https://www.elitezone.me',
        'https://staging.elitezone.me',

        // مبدأهای توسعه فقط در محیط local مجازند. قبلاً همیشه — از جمله روی
        // پروداکشن — در allowlist بودند و با supports_credentials=true یعنی هر
        // صفحه‌ای که روی خودِ ماشین کاربر از این پورت‌ها سرو شود (ابزار توسعه،
        // یک پکیج آلوده، بدافزار محلی) می‌توانست درخواستِ credentialed به APIِ
        // پروداکشن بزند. برای افزودن مبدأ در محیط‌های دیگر از CORS_EXTRA_ORIGINS
        // استفاده کنید، نه هاردکد.
        ...(env('APP_ENV', 'production') === 'local' ? [
            'http://localhost:3000',
            'http://localhost:8000',
            'http://localhost:5173',
            'http://localhost:5174',
        ] : []),

        ...array_map('trim', array_filter(explode(',', (string) env('CORS_EXTRA_ORIGINS', '')))),

        // استیجینگ هنوز SSL ندارد و روی http سرو می‌شود. این اوریجینِ http باید همیشه
        // مجاز باشد؛ قبلاً به APP_DEBUG گِیت شده بود و چون روی استیجینگ APP_DEBUG=false
        // است، preflightِ ورود با خطای CORS رد می‌شد (Redirect is not allowed for a
        // preflight request). اجازهٔ این اوریجینِ استیجینگ هیچ اثری روی امنیت پروداکشن
        // ندارد (پروداکشن دامنهٔ https خودش را دارد). با نصب SSL روی استیجینگ، خطِ https
        // بالا هم پوشش می‌دهد و این خط عملاً بی‌اثر می‌شود.
        'http://staging.elitezone.me',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache preflight for 24h — matches what the (now removed) hand-rolled Cors
    // middleware sent, so dropping it costs no extra OPTIONS round-trips.
    'max_age' => 86400,

    'supports_credentials' => true,

];

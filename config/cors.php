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

        // استیجینگ هنوز SSL ندارد و روی http سرو می‌شود، پس این اوریجینِ http لازم است
        // وگرنه preflightِ ورود با خطای CORS رد می‌شود.
        //
        // اما با supports_credentials=true یک اوریجینِ http در allowlistِ *پروداکشن*
        // یعنی: هر مهاجمی که بتواند ترافیکِ سادهٔ http://staging را MITM کند، می‌تواند
        // اسکریپت تزریق کند و از آن اوریجین درخواستِ credentialed به APIِ پروداکشن بزند
        // و پاسخ را بخواند. پس فقط در محیط‌های غیرپروداکشن مجاز است. برای استیجینگ،
        // APP_ENV=staging بگذارید (یا پس از نصب SSL، خطِ https بالا خودش پوشش می‌دهد).
        ...(env('APP_ENV', 'production') !== 'production' ? [
            'http://staging.elitezone.me',
        ] : []),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache preflight for 24h — matches what the (now removed) hand-rolled Cors
    // middleware sent, so dropping it costs no extra OPTIONS round-trips.
    'max_age' => 86400,

    'supports_credentials' => true,

];

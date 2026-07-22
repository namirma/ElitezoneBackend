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
        'http://localhost:3000',
        'http://localhost:8000',
        'http://localhost:5173',
        'http://localhost:5174',

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

    'max_age' => 0,

    'supports_credentials' => true,

];

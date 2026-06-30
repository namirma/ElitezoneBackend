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

        // استیجینگ هنوز SSL ندارد و روی http سرو می‌شود. این اوریجینِ http فقط در حالت
        // دیباگ مجاز می‌شود تا پروداکشن همچنان https-only بماند (طبق نظر تیم سرور).
        // وقتی SSL نصب شد، همان خطِ https بالا پوشش‌اش می‌دهد و این خط خود‌به‌خود بی‌اثر است.
        env('APP_DEBUG') ? 'http://staging.elitezone.me' : null,
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

<?php

return [

    'base_url' => env('PODIUM_BASE_URL', 'https://api.pod.ir'),
    'business_token' => env('PODIUM_BUSINESS_TOKEN'),

    'sc_api_keys' => [
        'single_settlement' => env('PODIUM_SC_API_KEY_SETTLEMENT'),
        'balances' => env('PODIUM_SC_API_KEY_BALANCES'),
        'batch_settlement' => env('PODIUM_SC_API_KEY_BATCH'),
        'settlement_inquiry' => env('PODIUM_SC_API_KEY_INQUIRY'),
    ],
    'sc2' => [
        'endpoints' => [
            'do' => '/srv/sc2/consumers/services/do',
        ],
        'content_type' => 'application/json',
    ],

    'endpoints' => [
        'do_service_call' => '/srv/sc/nzh/doServiceCall',
    ],
    'defaults' => [
        'sc_product_id' => (int) env('PODIUM_DEFAULT_SC_PRODUCT_ID', 367616),
        'wallet' => env('PODIUM_DEFAULT_WALLET', 'PODLAND_WALLET'),
        'currency' => env('PODIUM_DEFAULT_CURRENCY', 'IRR'),
        '_token_issuer' => 1,
        'timeout' => (int) env('PODIUM_TIMEOUT', 30),
    ],

    'limits' => [
        'max_amount' => (int) env('PODIUM_MAX_AMOUNT', 2000000000),
    ],

    // ── حساب سپرده شناسه‌دار (واریز مستقیم بانکی با شناسه واریز) ─────────────
    'deposit' => [
        'deposit_number'    => env('PODIUM_DEPOSIT_NUMBER'),                   // شماره سپرده بانکی
        'sc_api_key'        => env('PODIUM_DEPOSIT_SC_API_KEY'),               // کلید سرویس صورت‌حساب
        'product_entity_id' => env('PODIUM_DEPOSIT_ENTITY_ID', '46645563'),   // شناسه سرویس کلاسور
        'iban'              => env('PODIUM_DEPOSIT_IBAN'),                     // شماره شبا (IR + ۲۴ رقم)
        'bank_name'         => env('PODIUM_DEPOSIT_BANK_NAME', 'بانک پاسارگاد'),
        'account_holder'    => env('PODIUM_DEPOSIT_ACCOUNT_HOLDER', 'شرکت الیت‌زون'),
    ],

    // ── گزینه غیرفعال‌کردن بررسی Podium در محیط local/dev ──────────────────────
    // اگر Podium در دسترس نیست (VPN، توکن منقضی، محدودیت شبکه)، در .env بگذار:
    //   PODIUM_IDENTITY_VERIFICATION_ENABLED=false
    // ⚠️  در production این مقدار باید حتماً true (یا حذف‌شده = پیش‌فرض true) باشد.
    'identity_verification_enabled' => (bool) env('PODIUM_IDENTITY_VERIFICATION_ENABLED', true),

    'services' => [
        'identity_inquiry' => [
            'has_sandbox' => false,
            'sc_product_id' => (int) env('PODIUM_SC_PRODUCT_ID_IDENTITY_INQUIRY', 46659320),
            'sc_api_key' => env('PODIUM_SC_API_KEY_IDENTITY_INQUIRY'),
            'params' => ['nationalCode', 'birthDate'],
        ],
        'nid_mobile_match' => [
            'has_sandbox' => false,
            'sc_product_id' => (int) env('PODIUM_SC_PRODUCT_ID_MATCH_MOBILE_NID', 46645324),
            'sc_api_key' => env('PODIUM_SC_API_KEY_MATCH_MOBILE_NID'),
            'params' => ['nationalCode', 'mobileNumber'],
        ],
    ],
];

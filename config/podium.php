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

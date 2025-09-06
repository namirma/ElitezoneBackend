<?php

return [
    'base_url'       => env('PODIUM_BASE_URL', 'https://api.pod.ir'),
    'business_token' => env('PODIUM_BUSINESS_TOKEN'),
    'sc_api_keys' => [
        'single_settlement'  => env('PODIUM_SC_API_KEY_SETTLEMENT'),
        'balances'           => env('PODIUM_SC_API_KEY_BALANCES'),
        'batch_settlement'   => env('PODIUM_SC_API_KEY_BATCH'),
        'settlement_inquiry' => env('PODIUM_SC_API_KEY_INQUIRY'), 
    ],

    'endpoints'      => [
        'do_service_call' => '/srv/sc/nzh/doServiceCall',
    ],

    'defaults'       => [
        'sc_product_id' => 367616,
        'wallet'        => 'PODLAND_WALLET',
        'currency'      => 'IRR',
        '_token_issuer' => 1,
        'timeout'       => 30,
    ],

    'limits'         => [
        'max_amount' => 2000000000,
    ],
];

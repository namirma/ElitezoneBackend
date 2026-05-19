<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'tesna' => [
        'class' => \App\Services\Gateways\TesnaService::class,
        'url' => env('TESNA_BASE_URL', 'https://gateway.tesna.ir'),
        'headers' => [
            'x-api-key' => env('TESNA_API_KEY'),
            'Accept' => 'application/json',
            'Authorization' => env('TESNA_BEARER_TOKEN', ''),
        ],
    ],
    'podium_sc2' => [
        'class' => \App\Services\Gateways\PodiumScService::class,
        'url' => env('PODIUM_BASE_URL', 'https://api.pod.ir'),
        'headers' => [
            'Authorization' => 'Bearer '.env('PODIUM_BUSINESS_TOKEN'),
            'Content-Type' => 'application/json',
        ],
    ],
    'company_inquiry' => [
        'sc_api_key' => env('PODIUM_SC_API_KEY_COMPANY', '3105832128-1e267305e48a45e6a30541a855f6b597.XzIwMjUxMQ'),
        'sc_product_id' => env('PODIUM_SC_PRODUCT_ID_COMPANY', 46953),
    ],

    'sheba_inquiry' => [
        'sc_api_key' => env('PODIUM_SC_API_KEY_SHEBA'),
        'sc_product_id' => env('PODIUM_SC_PRODUCT_ID_SHEBA', 16530455),
    ],

    'card_inquiry' => [
        'sc_api_key' => env('PODIUM_SC_API_KEY_CARD'),
        'sc_product_id' => env('PODIUM_SC_PRODUCT_ID_CARD', 16530454),
    ],

    'tsms' => [
        'link' => env('TSMS_LINK', 'https://tsms.ir/url/tsmshttp.php'),
        'number' => env('TSMS_NUMBER'),
        'username' => env('TSMS_USERNAME'),
        'password' => env('TSMS_PASSWORD'),
    ],
];

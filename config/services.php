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
        ]
    ],
    'podium_sc2' => [
        'class' => \App\Services\Gateways\PodiumScService::class,
        'url'   => env('PODIUM_BASE_URL', 'https://api.pod.ir'),
        'headers' => [
            'Authorization' => 'Bearer ' . env('PODIUM_BUSINESS_TOKEN'),
            'Content-Type'  => 'application/json',
        ],
    ],

];

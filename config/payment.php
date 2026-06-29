<?php

return [

    /**
     * درصد سهم مشاور فنی (مهندس/پیمانکار)
     * این درصد از مبلغ subtotal (قبل از مالیات و تخفیف‌های سبد خرید)
     * محاسبه خواهد شد.
     * مثال: 2.0 = 2 درصد
     */
    'consultant_fee_percentage' => 2.0,

    'contractor_fee_percentage' => 2.0, // فعلا برابر با مهندس

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    | Per the design spec ("پروسه خرید"), the preferred rail is the Pasargad digital
    | account + API. The real integration is pending the bank licence, so the driver
    | is wired and config-driven but fails safe until credentials are present.
    | Never call env() outside config — application code reads config('payment.*').
    */
    'default_gateway' => env('PAYMENT_GATEWAY', 'wallet'),

    'gateways' => [

        'pasargad' => [
            // The driver treats itself as "configured" only when base_url + api_key +
            // terminal_id are all present; until then PasargadGateway fails safe.
            'base_url' => env('PASARGAD_BASE_URL'),
            'api_key' => env('PASARGAD_API_KEY'),
            'terminal_id' => env('PASARGAD_TERMINAL_ID'),
            'merchant_id' => env('PASARGAD_MERCHANT_ID'),
            'callback_url' => env('PASARGAD_CALLBACK_URL', env('APP_URL').'/api/payment/callback'),
            // Per-payment ceiling the bank currently enforces (200M Tomans = 2B Rials).
            'per_payment_limit' => (int) env('PASARGAD_PER_PAYMENT_LIMIT_RIALS', 2_000_000_000),
        ],

    ],
];

<?php

/**
 * Financial configuration for EliteZone.
 * All monetary amounts are in Rials (not Tomans).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation Threshold (Rials)
    |--------------------------------------------------------------------------
    |
    | Orders whose final_amount exceeds this value are eligible for the
    | reservation (hold) payment flow instead of direct purchase.
    |
    | 1,950,000,000 Rials = 195,000,000 Tomans = 195 million Tomans
    |
    */
    'reservation_threshold' => env('RESERVATION_THRESHOLD_RIALS', 1_950_000_000),

    /*
    |--------------------------------------------------------------------------
    | Hold Fee (Rials)
    |--------------------------------------------------------------------------
    |
    | Fixed fee charged to extend an existing reservation by 24 hours.
    | 50,000,000 Rials = 5,000,000 Tomans
    |
    */
    'hold_fee' => env('HOLD_FEE_RIALS', 50_000_000),

    /*
    |--------------------------------------------------------------------------
    | System (Saze) Wallet User ID
    |--------------------------------------------------------------------------
    |
    | The user_id that owns the platform's central "Saze" (WSSG) wallet.
    | Used for platform-revenue movements in WalletTransactionEngine.
    |
    */
    'system_user_id' => env('FINANCIAL_SYSTEM_USER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | VAT Escrow Wallet User ID
    |--------------------------------------------------------------------------
    |
    | The user_id that owns the dedicated VAT escrow (WVAT) wallet.
    | Keeping WVAT separate from WSSG allows the finance team to report and
    | remit collected VAT independently from platform revenue.
    |
    | Default: falls back to system_user_id so existing deployments continue
    | to work before a dedicated VAT user is created and the env var set.
    | Once FINANCIAL_VAT_USER_ID is set in .env, VAT movements go to their
    | own wallet row and can be audited separately.
    |
    */
    'vat_user_id' => env('FINANCIAL_VAT_USER_ID', env('FINANCIAL_SYSTEM_USER_ID', 1)),

];

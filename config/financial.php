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
    | The user_id that owns the platform's central "Saze" wallet.
    | Used for hold/escrow fund movements in WalletTransactionEngine.
    |
    */
    'system_user_id' => env('FINANCIAL_SYSTEM_USER_ID', 1),

];

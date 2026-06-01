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
    | Time windows (hours)
    |--------------------------------------------------------------------------
    |
    | All order/reservation time windows live here so they are never hardcoded
    | in Actions. Read them via config('financial.*'):
    |
    |   pending_order_ttl_hours      — a freshly placed (unpaid) order auto-cancels
    |                                  after this many hours (CheckReservationExpiryJob).
    |   reservation_validity_hours   — how long a paid reservation stays valid before
    |                                  it must be completed or extended.
    |   hold_fee_extension_hours     — how much paying the hold fee extends a reservation.
    |
    */
    'pending_order_ttl_hours' => (int) env('PENDING_ORDER_TTL_HOURS', 2),

    'reservation_validity_hours' => (int) env('RESERVATION_VALIDITY_HOURS', 2),

    'hold_fee_extension_hours' => (int) env('HOLD_FEE_EXTENSION_HOURS', 24),

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

    /*
    |--------------------------------------------------------------------------
    | Consultant lifetime commission cap multiplier
    |--------------------------------------------------------------------------
    |
    | Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
    | consultant may not exceed this multiple of the invoice they paid when purchasing
    | their badge. The per-consultant badge-purchase amount is not modelled yet, so the
    | cap is currently inert — wire it up in
    | ProcessWeighbridgeResultAction::consultantLifetimeCeiling() once that data exists.
    |
    */
    'consultant_lifetime_cap_multiplier' => (float) env('CONSULTANT_LIFETIME_CAP_MULTIPLIER', 2.0),

];

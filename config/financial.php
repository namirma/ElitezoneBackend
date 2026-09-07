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
    | Per the design spec ("پروسه خرید"): the Passargad gateway per-payment limit
    | is currently 200 million Tomans, so anything above it must go through the
    | reserve→complete flow. The threshold therefore tracks the gateway limit.
    |
    | 2,000,000,000 Rials = 200,000,000 Tomans = 200 million Tomans
    |
    */
    // حذف شد (بند ۱۶): تصمیمِ رزرو دیگر یک آستانهٔ مبلغیِ سراسری نیست، بلکه
    // توانِ پرداختِ همان کاربر است — PaymentBranchResolver.

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
    |
    | The paid extension (`hold_fee*`) is gone: extending a reservation is now free
    | and is the supplier's call, not something the buyer pays 5M Tomans for
    | (redesign spec §3.4). Reservation deadlines live on the order itself
    | (`reserve_deadline`), sourced from `supplier_profiles.reserve_time`.
    |
    */
    'pending_order_ttl_hours' => (int) env('PENDING_ORDER_TTL_HOURS', 2),

    'reservation_validity_hours' => (int) env('RESERVATION_VALIDITY_HOURS', 2),

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
    | EliteYAR lifetime commission cap multiplier
    |--------------------------------------------------------------------------
    |
    | Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
    | elite yar may not exceed this multiple of the invoice they paid when purchasing
    | their badge. The per-elite yar badge-purchase amount is stored in
    | users.badge_purchase_amount; WeighbridgeVatRouter::eliteYARLifetimeCeiling()
    | multiplies it by this value to derive the cap. A NULL badge_purchase_amount
    | (e.g. pioneer/normal elite yars) means uncapped.
    |
    */
    'elite_yar_lifetime_cap_multiplier' => (float) env('ELITE_YAR_LIFETIME_CAP_MULTIPLIER', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Wallet deposit limits (Rials)
    |--------------------------------------------------------------------------
    |
    | Per-deposit min, plus the Podium direct-deposit cap (200M Tomans = 2B
    | Rials). Read by WalletDepositRequest — never hardcode a limit there.
    |
    | The Shaparak gateway cap is NOT here: it lives in the admin-editable
    | `shaparak_value` sales setting, because the same number decides whether an
    | order goes to the gateway or to the reservation flow. This comment used to
    | claim the bank's cap was 20M Tomans; it is 200M, and the wrong figure here
    | made every shortfall between the two unpayable on the gateway rail.
    |
    */
    'deposit_min' => (int) env('DEPOSIT_MIN_RIALS', 10_000),

    /*
     * ⚠️ `deposit_max_shaparak` عمداً حذف شد — یک واقعیت دو تعریف داشت.
     *
     * سقفِ یک تراکنشِ درگاه از `SalesSettings::shaparakValue()` خوانده می‌شود
     * (تنظیمِ `shaparak_value`، قابلِ ویرایش توسط ادمین) چون **همان مقدار از
     * قبل تصمیم می‌گیرد سفارش به درگاه برود یا به رزرو**. این کلید مقدارِ
     * متفاوتی داشت (۲۰۰٬۰۰۰٬۰۰۰ در برابرِ ۲٬۰۰۰٬۰۰۰٬۰۰۰) و نتیجه‌اش این بود
     * که سایت مبلغی را برای شارژ پیشنهاد می‌داد که همین اعتبارسنجی ۴۲۲ می‌کرد.
     * سقفِ واقعیِ بانک ۲۰۰ میلیون تومان است (تأییدِ علی)، پس این عدد اشتباه
     * بود. آن را برنگردانید؛ افزودنِ دوباره‌اش یعنی بازگرداندنِ همان باگ.
     */
    'deposit_max_podium' => (int) env('DEPOSIT_MAX_PODIUM_RIALS', 2_000_000_000),

    /*
    |--------------------------------------------------------------------------
    | Weighbridge financial tolerance (Rials)
    |--------------------------------------------------------------------------
    |
    | Below this absolute difference, a post-weighbridge POO/PTS delta is treated
    | as rounding noise (no deficit branch). Read by ProcessWeighbridgeResultAction.
    |
    */
    'weighbridge_tolerance' => (int) env('WEIGHBRIDGE_TOLERANCE_RIALS', 1_000),

    /*
    |--------------------------------------------------------------------------
    | Default VAT rate (percent)
    |--------------------------------------------------------------------------
    |
    | Fallback used only when an order carries no `vat_rate` snapshot (legacy rows
    | that pre-date the snapshot column). Every payment action snapshots the live
    | rate onto `orders.vat_rate` and every later leg reads that snapshot, so this
    | value must never be relied on for a normal order.
    |
    | The literal 10.0 used to be hardcoded in three places
    | (ProcessPreScalePaymentAction, ProcessReservationCompletionAction,
    | WeighbridgeReconciler). If Iran's VAT rate changes, change it HERE (or via
    | env) — never re-inline it.
    |
    */
    'default_vat_rate' => (float) env('DEFAULT_VAT_RATE', 10.0),

];

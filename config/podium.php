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

    // ── برداشت خودکار کاربر (تسویه از کیف پول دیجیتال به شبای کاربر) ──────────
    // سرویس «برداشت از حساب دیجیتال با انتخاب روش انتقال» (toolCode = PAYA/SATNA).
    // شناسه سرویس: نسخه ۱ = 2467367، سندباکس = 158267، نسخه ۲ = 47567604.
    // تا وقتی enabled=false یا کلیدها خالی باشند، اکشن تسویه fail-safe است و هیچ
    // تماس بانکی نمی‌گیرد (مثل scaffold درگاه پاسارگاد) تا پولی به‌اشتباه جابجا نشود.
    'settlement' => [
        'enabled' => (bool) env('PODIUM_SETTLEMENT_ENABLED', false),
        'sc_product_id' => env('PODIUM_SETTLEMENT_SC_PRODUCT_ID'),     // 2467367 prod / 158267 sandbox
        'sc_api_key' => env('PODIUM_SETTLEMENT_SC_API_KEY'),
        // مبلغ ≥ این آستانه با SATNA (آنی)، کمتر از آن با PAYA (ارزان، چرخه‌ای) تسویه می‌شود.
        'satna_threshold' => (int) env('PODIUM_SETTLEMENT_SATNA_THRESHOLD', 1000000000),
        // کارمزد PAYA که پادیوم از کیف پول دیجیتال «ما» (نه کاربر) کسر می‌کند — صرفاً اطلاعاتی.
        'paya_fee' => (int) env('PODIUM_SETTLEMENT_PAYA_FEE', 12000),
        'detail_type' => env('PODIUM_SETTLEMENT_DETAIL_TYPE', 'DETAIL_TYPE_GENERAL_COST'),
        // اگر true شود، تسویه بلافاصله روی درخواست کاربر و بدون تأیید ادمین شلیک می‌شود.
        // پیش‌فرض false: مدل امن «تأیید ادمین → واریز خودکار».
        'auto_on_request' => (bool) env('PODIUM_SETTLEMENT_AUTO_ON_REQUEST', false),
    ],

    // ── حساب سپرده شناسه‌دار (واریز مستقیم بانکی با شناسه واریز) ─────────────
    'deposit' => [
        'deposit_number' => env('PODIUM_DEPOSIT_NUMBER'),                   // شماره سپرده بانکی
        'sc_api_key' => env('PODIUM_DEPOSIT_SC_API_KEY'),               // کلید سرویس صورت‌حساب
        'product_entity_id' => env('PODIUM_DEPOSIT_ENTITY_ID', '46645563'),   // شناسه سرویس کلاسور
        'iban' => env('PODIUM_DEPOSIT_IBAN'),                     // شماره شبا (IR + ۲۴ رقم)
        'bank_name' => env('PODIUM_DEPOSIT_BANK_NAME', 'بانک پاسارگاد'),
        'account_holder' => env('PODIUM_DEPOSIT_ACCOUNT_HOLDER', 'شرکت الیت‌زون'),

        /*
        | صورت‌حسابِ سپرده — صفحه‌بندی و پنجرهٔ polling.
        |
        | بانک تراکنش‌ها را صفحه‌به‌صفحه می‌دهد و **واریز و برداشت را با هم**
        | برمی‌گرداند؛ فیلترِ «فقط واریز» سمتِ ماست. پس یک صفحهٔ کوتاه یعنی
        | ممکن است واریزهای واقعی در صفحهٔ بعد جا مانده باشند.
        */
        'statement' => [
            // اندازهٔ هر صفحه در فراخوانِ پادیوم.
            'page_size' => (int) env('PODIUM_STATEMENT_PAGE_SIZE', 100),

            // سقفِ صفحه — گاردِ حلقهٔ بی‌پایان اگر بانک همیشه صفحهٔ پر برگرداند.
            // با page_size پیش‌فرض یعنی حداکثر ۵۰٬۰۰۰ ردیف در یک اجرا.
            'max_pages' => (int) env('PODIUM_STATEMENT_MAX_PAGES', 500),

            // زمانِ منطقهٔ بانک. `app.timezone` روی UTC است و تاریخِ شمسی/ساعت باید
            // محلی باشد، وگرنه پنجره ۳٫۵ ساعت جابه‌جا می‌شود.
            'timezone' => env('PODIUM_STATEMENT_TIMEZONE', 'Asia/Tehran'),

            // هم‌پوشانیِ عمدی روی هر اجرا. تکرار بی‌خطر است چون `doc_number`
            // ایندکسِ یکتا دارد و `ProcessPodiumDepositAction` زودهنگام رد می‌کند.
            'overlap_minutes' => (int) env('PODIUM_STATEMENT_OVERLAP_MINUTES', 5),

            // وقتی هیچ watermarkی نیست (اولین اجرا یا پاک شدن تنظیم).
            'default_window_minutes' => (int) env('PODIUM_STATEMENT_DEFAULT_WINDOW_MINUTES', 15),

            // سقفِ عقبگرد پس از یک قطعیِ طولانی — تا درخواستِ «کلِ سالِ گذشته» نرود.
            'max_lookback_minutes' => (int) env('PODIUM_STATEMENT_MAX_LOOKBACK_MINUTES', 10080), // ۷ روز
        ],
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

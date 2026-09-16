<?php

return [
    'inventory' => [
        // بازه زمانی تحلیل فروش (چند روز گذشته؟)
        'sales_period_days' => env('INTEL_SALES_DAYS', 30),

        // بازه زمانی تحلیل جستجو
        'search_period_days' => env('INTEL_SEARCH_DAYS', 7),

        // بازه پیش‌بینی (برای چند روز آینده پیش‌بینی کنیم؟)
        'prediction_days' => env('INTEL_PREDICTION_DAYS', 7),

        // ضریب تبدیل جستجو→خرید پیش‌فرض (وقتی داده‌ی کافی per-product نیست).
        // در InventoryIntelligenceService در صورت وجود داده، نرخ واقعی هر محصول
        // (sales / searches) جایگزین این می‌شود و در بازه [min,max] کلَمپ می‌شود.
        'search_conversion_rate' => env('INTEL_CONVERSION_RATE', 0.1),
        'conversion_rate_min' => env('INTEL_CONVERSION_MIN', 0.01),
        'conversion_rate_max' => env('INTEL_CONVERSION_MAX', 0.5),

        // حداقل جستجویی که نگران‌کننده است
        'high_search_threshold' => env('INTEL_HIGH_SEARCH', 50),

        // نگهداری تاریخچه‌ی پیش‌بینی برای backtesting — به‌جای truncate در هر اجرا.
        // اسنپ‌شات‌های قدیمی‌تر از این تعداد روز پاک می‌شوند.
        'prediction_retention_days' => env('INTEL_PREDICTION_RETENTION_DAYS', 90),

        // اندازه‌ی chunk هنگام پیمایش واریانت‌ها (جلوگیری از انفجار حافظه).
        'chunk_size' => env('INTEL_CHUNK_SIZE', 500),

        // ضریب فصلی تقاضا بر اساس ماهِ شمسی (۱=فروردین … ۱۲=اسفند).
        // مصالح ساختمانی در فصل‌های گرم (ساخت‌وساز) تقاضای بالاتری دارند.
        // مقدار 1.0 یعنی بدون تعدیل؛ از توزیع واقعی فروش قابل کالیبره است.
        'seasonal_multipliers' => [
            1 => 1.05,  // فروردین
            2 => 1.20,  // اردیبهشت
            3 => 1.30,  // خرداد
            4 => 1.30,  // تیر
            5 => 1.25,  // مرداد
            6 => 1.20,  // شهریور
            7 => 1.10,  // مهر
            8 => 1.00,  // آبان
            9 => 0.85,  // آذر
            10 => 0.75, // دی
            11 => 0.75, // بهمن
            12 => 0.90, // اسفند
        ],
    ],

    'rfm' => [
        // حالت امتیازدهی: 'fixed' (آستانه‌های ثابت زیر) یا 'quintile'
        // (پنجک‌بندی از توزیع واقعی داده — با رشد پلتفرم خودکار تنظیم می‌شود).
        'scoring_mode' => env('RFM_SCORING_MODE', 'fixed'),

        // آستانه‌های ثابت — کلید = امتیاز، مقدار = سقفِ بازه.
        // Recency: روزهای کمتر = امتیاز بالاتر (تازه‌تر).
        'recency' => [
            5 => 30,
            4 => 60,
            3 => 90,
            2 => 180,
            // بیشتر از 180 → امتیاز 1
        ],
        // Frequency: تعداد سفارش بیشتر = امتیاز بالاتر. کلید = کف بازه.
        'frequency' => [
            5 => 20,
            4 => 10,
            3 => 5,
            2 => 2,
            // کمتر از 2 → امتیاز 1
        ],
        // Monetary: مبلغ بیشتر = امتیاز بالاتر (ریال). کلید = کف بازه.
        'monetary' => [
            5 => 5_000_000_000,
            4 => 1_000_000_000,
            3 => 500_000_000,
            2 => 100_000_000,
            // کمتر → امتیاز 1
        ],

        // آستانه‌ی churn — مشتری با recency بیشتر از این، «از دست رفته» (lost) شمرده
        // می‌شود؛ مبنای محاسبه‌ی churn rate در داشبورد.
        'churn_days' => env('RFM_CHURN_DAYS', 180),
    ],

    'searches' => [
        // ⚠️ این سیاست وجود نداشت. `GET /api/search` عمومی است و به‌ازای **هر**
        // درخواست یک ردیف در `searches` می‌نویسد — همراه با `user_id`، عبارتِ
        // تایپ‌شده، IP (ناشناس‌شده) و User-Agent. `product_views` و سبدهای مهمان
        // هر دو نگهداشت داشتند، `searches` هیچ: هم رشدِ نامحدود، هم نگه‌داشتنِ
        // ابدیِ تاریخچه‌ی جستجوی افراد.
        //
        // ۹۰ روز = همان عددِ `product_views`، چون داده از همان جنس است.
        'retention_days' => env('SEARCHES_RETENTION_DAYS', 90),
    ],

    'product_views' => [
        // نگهداری داده‌ی خامِ بازدید (شامل IP/UserAgent) — حریم خصوصی + جلوگیری از
        // رشد نامحدود جدول. داده‌ی تجمیعی در product_view_daily بلندمدت می‌ماند.
        'retention_days' => env('PRODUCT_VIEWS_RETENTION_DAYS', 90),

        // پنجره‌ی dedup: بازدید تکراری همان session از همان محصول در این چند ثانیه
        // ثبت نمی‌شود (جلوگیری از تورم view_count با رفرش/اسکرپ).
        'dedup_seconds' => env('PRODUCT_VIEWS_DEDUP_SECONDS', 30),

        // بازدیدِ واریانت (صفحه‌ی محصولِ سایت): یک‌بار در روز به‌ازای هر بازدیدکننده.
        'variant_dedup_seconds' => env('PRODUCT_VARIANT_VIEWS_DEDUP_SECONDS', 86400),

        // الگوهای User-Agent رباتی که نباید در آنالیتیکس شمرده شوند.
        'bot_user_agents' => [
            'bot', 'crawler', 'spider', 'slurp', 'mediapartners',
            'curl', 'wget', 'python-requests', 'okhttp', 'headless',
            'selenium', 'phantomjs', 'puppeteer', 'playwright',
            'go-http-client', 'java/', 'libwww', 'scrapy', 'ahrefs', 'semrush',
        ],
    ],
];

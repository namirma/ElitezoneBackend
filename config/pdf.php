<?php

return [
    'mode' => 'utf-8',
    'format' => 'A4',
    'author' => 'EliteZone',
    'subject' => 'Invoice',
    'keywords' => 'PDF, Laravel, Invoice',
    'creator' => 'EliteZone App',
    'display_mode' => 'fullpage',
    'tempDir' => base_path('storage/app/mpdf'),
    'pdf_a' => false,
    'pdf_a_auto' => false,
    'icc_profile_path' => '',

    // تنظیمات فونت (حیاتی برای فارسی)
    // ⚠️ پکیج carlos-meneses/laravel-mpdf فقط کلیدهای 'custom_font_dir' و
    // 'custom_font_data' را می‌خواند (نه font_path/font_data). با نام‌گذاری اشتباه،
    // فونت یکان‌بخ هرگز به mPDF داده نمی‌شد و خروجی PDF به DejaVu برمی‌گشت
    // (در حالی‌که HTML به‌خاطر @font-face درست بود). رجوع به LaravelMpdf.php خط ۴۵-۴۸.
    'custom_font_dir' => storage_path('fonts').DIRECTORY_SEPARATOR, // پوشه‌ای که فایل‌های ttf آنجاست
    'custom_font_data' => [
        'yekan' => [
            'R' => 'YekanBakh-Regular.ttf',
            'B' => 'YekanBakh-Bold.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
        'yekanbakh' => [
            'R' => 'YekanBakh-Regular.ttf',
            'B' => 'YekanBakh-Bold.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
    ],
];

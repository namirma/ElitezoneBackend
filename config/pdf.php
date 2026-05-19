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
    'font_path' => base_path('storage/fonts/'), // مطمئن شوید پوشه storage/fonts وجود دارد
    'font_data' => [
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

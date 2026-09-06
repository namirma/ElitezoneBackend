<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

/*
|--------------------------------------------------------------------------
| Backup configuration (spatie/laravel-backup)
|--------------------------------------------------------------------------
|
| این پکیج نصب بود ولی هیچ کانفیگ و هیچ زمان‌بندی نداشت — یعنی سیستمی که کیف پول
| و لجر مالی دارد هیچ نسخهٔ پشتیبانی نمی‌گرفت. این فایل حداقلِ عملیاتی را می‌دهد:
|
|   backup:run --only-db   → دامپ MySQL، روزانه ۰۱:۰۰ (Console/Kernel)
|   backup:clean           → اعمال سیاست نگهداشت، روزانه ۰۱:۴۵
|
| مقصد پیش‌فرض دیسک خصوصیِ `financial` است (خارج از storage:link و غیرقابل‌دسترس
| از وب). دامپ دیتابیس شامل کدملی، شبا و شمارهٔ کارت است — هرگز روی دیسک `public`
| یا هر مقصدِ URL-پذیر ننویسید. برای نگه‌داشتِ نسخه در خارج از سرور، نام دیسک s3 را
| به BACKUP_DISKS اضافه کنید (توصیه‌شده: یک off-site واقعی).
|
*/

return [

    'backup' => [

        'name' => env('BACKUP_ARCHIVE_NAME', 'elitezone'),

        'source' => [
            // فقط دیتابیس. فایل‌های آپلودی (رسیدها/فاکتورها) حجم زیادی دارند و
            // چرخهٔ پشتیبان‌گیریِ جداگانه‌ای می‌خواهند؛ افزودنشان اینجا دامپ روزانه
            // را غیرعملی می‌کند.
            'files' => [
                'include' => [],
                'exclude' => [],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],

            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,

            'filename_prefix' => '',

            // دیسک خصوصی — دامپ حاوی PII و اطلاعات بانکی است.
            'disks' => array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'financial'))),
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
    | گزارش موفقیت/شکست. پیش‌فرض فقط لاگ است تا نصب بدون تنظیمِ ایمیل سرصدا نکند؛
    | با BACKUP_NOTIFICATION_EMAIL ایمیل هم فعال می‌شود.
    */
    'notifications' => [

        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@elitezone.me'),
                'name' => env('MAIL_FROM_NAME', 'EliteZone Backup'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    'monitor_backups' => [
        [
            'name' => env('BACKUP_ARCHIVE_NAME', 'elitezone'),
            'disks' => array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'financial'))),
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => (int) env('BACKUP_KEEP_ALL_DAYS', 7),
            'keep_daily_backups_for_days' => (int) env('BACKUP_KEEP_DAILY_DAYS', 30),
            'keep_weekly_backups_for_weeks' => (int) env('BACKUP_KEEP_WEEKLY_WEEKS', 8),
            'keep_monthly_backups_for_months' => (int) env('BACKUP_KEEP_MONTHLY_MONTHS', 12),
            'keep_yearly_backups_for_years' => (int) env('BACKUP_KEEP_YEARLY_YEARS', 3),
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_MAX_STORAGE_MB', 10000),
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],
];

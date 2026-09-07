<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    | ─────────────────────────────────────────────────────────────────────────
    | Cloud-ready disks (ArvanCloud / any S3-compatible object storage)
    | ─────────────────────────────────────────────────────────────────────────
    | Every disk below is env-driven: it stays on the server's local filesystem
    | by default (dev/staging) and moves to object storage by ONLY changing env —
    | no application code changes, because all uploads use the disk abstraction
    | (Storage::disk('public')->put/url/...). Two logical targets:
    |   • public  → FILESYSTEM_PUBLIC_DRIVER   (media: avatars, product/badge images …)
    |   • private → FILESYSTEM_PRIVATE_DRIVER  (financial receipts, invoices, documents)
    | To switch to ArvanCloud, set those to `s3` and fill the AWS_* vars (Arvan is
    | S3-compatible). See .env.cloud.example for the full contract.
    */

    'disks' => [

        // Generic PRIVATE disk (framework default). Holds private documents such as
        // job-experience credentials. Cloud-switchable via FILESYSTEM_PRIVATE_DRIVER.
        'local' => [
            'driver' => env('FILESYSTEM_PRIVATE_DRIVER', 'local'),
            'root' => env('FILESYSTEM_PRIVATE_DRIVER', 'local') === 's3'
                ? env('FILESYSTEM_LOCAL_ROOT', 'documents')
                : storage_path('app'),
            'visibility' => 'private',
            'throw' => false,
            // ── S3 / ArvanCloud (used only when driver=s3) — PRIVATE bucket ──
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PRIVATE_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],

        // PUBLIC media (avatars, product/category/badge/factory images, ticket files).
        // URLs are always built via Storage::disk('public')->url(), so flipping the
        // driver to s3 is enough. Cloud-switchable via FILESYSTEM_PUBLIC_DRIVER.
        'public' => [
            'driver' => env('FILESYSTEM_PUBLIC_DRIVER', 'local'),
            'root' => env('FILESYSTEM_PUBLIC_DRIVER', 'local') === 's3'
                ? env('FILESYSTEM_PUBLIC_ROOT', '')
                : storage_path('app/public'),
            // local → APP_URL/storage ; cloud → FILESYSTEM_PUBLIC_URL, or null so the
            // S3 adapter derives it from endpoint+bucket.
            //
            // ⚠️ The `APP_URL.'/storage'` fallback must NEVER apply to the s3 driver.
            // It did, and it produced a silent, total failure: with
            // FILESYSTEM_PUBLIC_DRIVER=s3 and FILESYSTEM_PUBLIC_URL unset, every upload
            // was written to the bucket while `Storage::disk('public')->url()` handed
            // out `http://localhost:8000/storage/...` — a path that cannot exist,
            // because nothing was ever written there. Measured on the dev box: three
            // real avatars sitting safely in the bucket, every one of them a 404 for
            // the user who uploaded it. Nothing errored; the upload endpoint returned
            // 200 with a URL, and the image simply never appeared. The same broke
            // page-block images and every other public upload at once.
            //
            // Leaving it null for s3 is the correct default: flysystem builds the URL
            // from the endpoint and bucket it is already configured with, so a bare
            // driver switch works, and FILESYSTEM_PUBLIC_URL stays available for a CDN
            // domain in front of the bucket.
            'url' => env('FILESYSTEM_PUBLIC_DRIVER', 'local') === 's3'
                ? env('FILESYSTEM_PUBLIC_URL')
                : env('FILESYSTEM_PUBLIC_URL', env('APP_URL').'/storage'),
            'visibility' => 'public',
            'throw' => false,
            // ── S3 / ArvanCloud (used only when driver=s3) — PUBLIC bucket ──
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],

        // PRIVATE financial documents (deposit/supplier/order receipts + invoice PDFs).
        // NEVER public — served only through authenticated download endpoints (and on
        // s3 can use signed temporaryUrl). Deliberately NOT in the `links` array below.
        // Cloud-switchable via FILESYSTEM_PRIVATE_DRIVER.
        'financial' => [
            'driver' => env('FILESYSTEM_PRIVATE_DRIVER', 'local'),
            'root' => env('FILESYSTEM_PRIVATE_DRIVER', 'local') === 's3'
                ? env('FILESYSTEM_FINANCIAL_ROOT', 'financial')
                : storage_path('app/financial'),
            'visibility' => 'private',
            'throw' => false,
            // ── S3 / ArvanCloud (used only when driver=s3) — PRIVATE bucket ──
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PRIVATE_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],

        // Generic s3 disk (kept for ad-hoc use).
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

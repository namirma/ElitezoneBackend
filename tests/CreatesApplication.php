<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * نامِ دیتابیس‌های زنده/توسعه که تست هرگز نباید به آن‌ها وصل شود.
     * هر تستی که به یکی از این‌ها وصل شود، کل اجرا را متوقف می‌کند.
     */
    private const FORBIDDEN_TEST_DATABASES = ['laravel'];

    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // ⚠️ محافظِ ایمنی — قبل از اجرای هر تست (و قبل از migrate:fresh مربوط به
        // RefreshDatabase) اجرا می‌شود، چون createApplication() در setUp پیش از
        // setUpTraits فراخوانی می‌شود. اگر تست به دیتابیس زنده وصل باشد، اینجا
        // throw می‌کنیم تا هیچ‌گاه داده‌ی واقعی drop/پاک نشود.
        $this->guardAgainstLiveDatabase($app);

        return $app;
    }

    /**
     * اگر connectionِ پیش‌فرضِ تست به یک دیتابیسِ زنده اشاره کند، اجرا را متوقف کن.
     */
    private function guardAgainstLiveDatabase(Application $app): void
    {
        $defaultConnection = $app['config']->get('database.default');
        $databaseName = $app['config']->get("database.connections.{$defaultConnection}.database");

        if (in_array($databaseName, self::FORBIDDEN_TEST_DATABASES, true)) {
            throw new RuntimeException(
                "🛑 توقف تست‌ها: اتصال به دیتابیسِ زنده «{$databaseName}» شناسایی شد. ".
                'تست‌ها باید روی یک دیتابیسِ مخصوص (مثل laravel_testing) اجرا شوند. '.
                'مقدار DB_DATABASE را در phpunit.xml یا .env.testing بررسی کنید. '.
                '(هیچ داده‌ای پاک نشد — اجرا پیش از هر تخریبی متوقف شد.)'
            );
        }
    }
}

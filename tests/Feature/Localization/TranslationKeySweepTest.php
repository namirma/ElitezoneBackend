<?php

namespace Tests\Feature\Localization;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * هر کلیدِ ترجمه‌ی **لیترالی** که در `app/` نوشته شده باید واقعاً حل شود.
 *
 * ‏`__()` وقتی کلید را پیدا نکند خودِ کلید را برمی‌گرداند — بدون خطا، بدون
 * لاگ. و `success()`/`error()` پیامشان را از همان `__()` می‌گیرند، پس بدنه‌ی
 * پاسخ عیناً می‌شود `global.forbidden`.
 *
 * ‏`TranslationKeysResolveTest` این را می‌سنجد ولی روی یک **فهرستِ دستی** —
 * یعنی دقیقاً کلیدهایی که یک‌بار کسی به آن‌ها فکر کرده. این تست به‌جایش کلِ
 * `app/` را جارو می‌کند. اولین اجرایش شش کلیدِ زنده پیدا کرد
 * (`admin.reports.*`) که به‌خاطر یک کلیدِ آرایه‌ی تکراری در `lang/fa/admin.php`
 * ناپدید شده بودند — تا حدی که ادمین ایمیلی می‌گرفت که موضوعش عیناً
 * `admin.reports.ready_subject` بود.
 */
class TranslationKeySweepTest extends TestCase
{
    /**
     * کلیدهایی که عمداً **پیشوند**اند و در زمان اجرا با یک مقدار پویا کامل
     * می‌شوند — مثل `__('orders.status.'.$status)`. خودِ پیشوند هرگز حل
     * نمی‌شود و نباید انتظار داشت بشود.
     *
     * @var list<string>
     */
    private const DYNAMIC_PREFIXES = [
        'dashboard.alerts.',
        'orders.order_status.',
        'orders.payment_status.',
        'orders.status.',
        'shipping.basis.',
    ];

    #[Test]
    public function every_literal_translation_key_in_the_app_resolves(): void
    {
        $this->app->setLocale('fa');

        $unresolved = [];

        foreach ($this->literalKeys() as $key => $files) {
            if (in_array($key, self::DYNAMIC_PREFIXES, true)) {
                continue;
            }

            if (trans($key) === $key) {
                $unresolved[] = $key.'  ← '.implode(', ', array_slice($files, 0, 2));
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "این کلیدها حل نمی‌شوند و نامِ خامشان به کاربر نشان داده می‌شود:\n  "
            .implode("\n  ", $unresolved)
        );
    }

    /**
     * کلیدِ آرایه‌ی تکراری در یک فایلِ زبان **بی‌صدا** بلوکِ قبلی را می‌بلعد.
     *
     * این همان علتِ ریشه‌ای شش کلیدِ گم‌شده بود: `lang/fa/admin.php` دو بار
     * `'reports' => [...]` داشت و بلوکِ دوم اولی را کامل بازنویسی می‌کرد.
     * PHP نه خطا می‌دهد نه هشدار.
     */
    #[Test]
    public function no_language_file_declares_the_same_top_level_key_twice(): void
    {
        $duplicates = [];

        foreach (glob(lang_path('fa/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // کلیدهای سطحِ اول: دقیقاً چهار فاصله تورفتگی دارند.
            preg_match_all("/^    '([a-z0-9_]+)'\s*=>/im", $source, $matches);

            $counts = array_count_values($matches[1] ?? []);

            foreach ($counts as $key => $count) {
                if ($count > 1) {
                    $duplicates[] = basename($file).": '{$key}' ×{$count}";
                }
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            "کلیدِ تکراری در فایلِ زبان — دومی بی‌صدا اولی را بازمی‌نویسد:\n  "
            .implode("\n  ", $duplicates)
        );
    }

    /**
     * کلیدهای لیترالِ نقطه‌دارِ پاس‌شده به `__()` / `error()` / `success()` /
     * `created()`.
     *
     * @return array<string, list<string>> کلید ← فایل‌هایی که استفاده‌اش می‌کنند
     */
    private function literalKeys(): array
    {
        $root = app_path();
        $keys = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/(?:__|error|success|created)\(\s*'([a-z0-9_]+\.[a-z0-9_.]+)'/i",
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] ?? [] as $key) {
                $keys[$key][] = str_replace([$root, '\\'], ['app', '/'], $file->getPathname());
            }
        }

        ksort($keys);

        return array_map(fn (array $files) => array_values(array_unique($files)), $keys);
    }
}

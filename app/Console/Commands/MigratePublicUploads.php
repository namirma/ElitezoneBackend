<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * فایل‌هایی که روی پوشه‌ی محلیِ `storage/app/public` مانده‌اند را به دیسکِ
 * عمومیِ پیکربندی‌شده (باکت) می‌برد.
 *
 * چرا لازم است: وقتی `FILESYSTEM_PUBLIC_DRIVER` از `local` به `s3` عوض شد،
 * آپلودهای **جدید** به باکت رفتند ولی فایل‌های قدیمی روی دیسکِ محلی جا ماندند.
 * تا وقتی آدرس‌ها هم محلی تولید می‌شدند این دیده نمی‌شد؛ به‌محضِ اینکه تولیدِ
 * آدرس درست شد و به باکت اشاره کرد، هر فایلِ جامانده ۴۰۴ شد — از جمله تصاویری
 * که هر بازدیدکننده می‌بیند.
 *
 * ⚠️ فقط دیسکِ **عمومی**. فایل‌های خصوصی (مدارکِ سوابق شغلی، پیوستِ تیکت،
 * اسناد مالی) عمداً روی دیسکِ خصوصی‌اند و بردنشان به باکتِ عمومی یعنی منتشر
 * کردنِ مدارکِ شخصیِ کاربران. این دستور هرگز به آن‌ها دست نمی‌زند.
 *
 * پیش‌فرض خشک است (`--dry-run` لازم نیست) و چیزی را حذف نمی‌کند: کپی می‌کند و
 * نسخه‌ی محلی سرِ جایش می‌ماند، پس اجرای دوباره‌اش بی‌خطر است.
 */
class MigratePublicUploads extends Command
{
    protected $signature = 'storage:migrate-public
                            {--apply : فایل‌ها را واقعاً کپی کن (بدون این فقط گزارش می‌دهد)}';

    protected $description = 'فایل‌های جامانده روی پوشه‌ی محلیِ public را به دیسکِ عمومیِ پیکربندی‌شده کپی می‌کند';

    public function handle(): int
    {
        $driver = config('filesystems.disks.public.driver');

        if ($driver === 'local') {
            $this->info('دیسکِ عمومی محلی است؛ چیزی برای انتقال نیست.');

            return self::SUCCESS;
        }

        $root = storage_path('app/public');

        if (! is_dir($root)) {
            $this->info('پوشه‌ی محلیِ public وجود ندارد؛ چیزی برای انتقال نیست.');

            return self::SUCCESS;
        }

        $all = $this->localFiles($root);

        if ($all === []) {
            $this->info('پوشه‌ی محلیِ public خالی است.');

            return self::SUCCESS;
        }

        // برخی پوشه‌ها زمانی عمومی بودند و بعد **خصوصی** شدند؛ فایل‌های آن دوره
        // هنوز فیزیکاً این‌جا افتاده‌اند. اگر صرفاً «هرچه در این پوشه است» را
        // منتقل کنیم، مدارکِ شخصیِ کاربران روی یک باکتِ عمومی منتشر می‌شود.
        // پس با فهرستِ صریح رد می‌شوند، و چون پیش‌فرض ردکردن است، پوشه‌ای که
        // فراموش شود هم منتشر نمی‌شود، فقط جا می‌ماند.
        [$local, $skipped] = $this->partitionPrivate($all);

        if ($skipped !== []) {
            $this->warn(count($skipped).' فایلِ خصوصی رد شد (به باکتِ عمومی منتقل نمی‌شوند):');
            foreach ($skipped as $path) {
                $this->line('  - '.$path);
            }
            $this->newLine();
        }

        if ($local === []) {
            $this->info('فایلِ عمومیِ جامانده‌ای نیست.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('public');

        // یک‌بار فهرست می‌گیریم، نه یک درخواست به‌ازای هر فایل — روی یک باکتِ
        // واقعی تفاوتش بینِ چند ثانیه و چند دقیقه است.
        $remote = array_flip($disk->allFiles());

        $missing = array_values(array_filter($local, static fn (string $p): bool => ! isset($remote[$p])));

        if ($missing === []) {
            $this->info('همه‌ی '.count($local).' فایلِ محلی از قبل روی دیسکِ عمومی هستند.');

            return self::SUCCESS;
        }

        $this->warn(count($missing).' فایل روی دیسکِ عمومی نیست:');
        foreach ($missing as $path) {
            $this->line('  '.$path);
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('گزارش بود، نه انتقال. برای انجامِ واقعی: php artisan storage:migrate-public --apply');

            return self::SUCCESS;
        }

        $copied = 0;
        $failed = 0;

        foreach ($missing as $path) {
            $stream = @fopen($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path), 'rb');

            if ($stream === false) {
                $this->error('  خواندنِ فایل ممکن نشد: '.$path);
                $failed++;

                continue;
            }

            // استریم، نه خواندنِ کاملِ فایل در حافظه — بینِ این فایل‌ها ویدیو و
            // PDF هم هست.
            $ok = $disk->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($ok) {
                $copied++;
            } else {
                $this->error('  نوشتن روی دیسکِ عمومی ناموفق: '.$path);
                $failed++;
            }
        }

        $this->newLine();
        $this->info('کپی‌شده: '.$copied.' | ناموفق: '.$failed);
        $this->comment('نسخه‌ی محلی حذف نشد؛ بعد از اطمینان از درست‌بودنِ آدرس‌ها می‌توانید پاکش کنید.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * پوشه‌هایی که محتوایشان خصوصی است و هرگز نباید روی باکتِ عمومی بروند.
     *
     * هرکدام زمانی روی دیسکِ عمومی نوشته می‌شد و بعد به دیسکِ خصوصی منتقل شد؛
     * فایل‌های آن دوره هنوز فیزیکاً در `storage/app/public` هستند. مبنا خودِ کد
     * است، نه حافظه: `TicketFile::DISK` می‌گوید پیوستِ تیکت دیگر عمومی نیست، و
     * `CLAUDE.md` صریح می‌گوید مدارکِ سوابق شغلی هرگز روی دیسکِ عمومی نروند.
     *
     * @return list<string>
     */
    private function privateDirectories(): array
    {
        $dirs = ['job_experiences'];

        if (\App\Models\TicketFile::DISK !== 'public') {
            $dirs[] = \App\Models\TicketFile::DIRECTORY;
        }

        return $dirs;
    }

    /**
     * جداکردنِ فایل‌های عمومی از خصوصی.
     *
     * @param  list<string>  $files
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionPrivate(array $files): array
    {
        $private = $this->privateDirectories();
        $public = [];
        $skipped = [];

        foreach ($files as $path) {
            $dir = explode('/', $path)[0];

            if (in_array($dir, $private, true)) {
                $skipped[] = $path;

                continue;
            }

            $public[] = $path;
        }

        return [$public, $skipped];
    }

    /**
     * مسیرهای نسبیِ همه‌ی فایل‌های پوشه‌ی محلیِ public، با اسلشِ رو‌به‌جلو.
     *
     * @return list<string>
     */
    private function localFiles(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($root) + 1)
            );

            if (str_ends_with($relative, '.gitignore')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }
}

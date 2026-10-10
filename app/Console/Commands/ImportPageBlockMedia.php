<?php

namespace App\Console\Commands;

use Database\Seeders\PageBlocksSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * عکس‌های بلوک‌های سیدشده را از پوشه‌ی تصاویرِ نمونه‌ی طرح (`prototype/img`) رویِ دیسکِ public کپی
 * می‌کند — فقط برای ماشینِ توسعه. سیدر به `page-blocks/v276/<مسیرِ نسبی در prototype/img>` اشاره
 * می‌کند، پس مسیرِ مقصد از خودِ مسیرِ مبدأ ساخته می‌شود و هیچ نقشه‌ی دومی لازم نیست.
 *
 * فایلِ موجود بازنویسی نمی‌شود (عکسی که ادمین جایگزین کرده دست نمی‌خورد). نبودنِ هر فایل گزارش
 * می‌شود و خروجی ناموفق است، تا بلوکی با عکسِ شکسته بی‌صدا نماند.
 */
class ImportPageBlockMedia extends Command
{
    protected $signature = 'page-blocks:import-media {source : پوشه‌ی img نمونه‌ی طرح (prototype/img)}';

    protected $description = 'کپیِ عکس‌های بلوک‌های سیدشده‌ی صفحه‌ساز به دیسکِ public (فقط توسعه)';

    public function handle(): int
    {
        $source = rtrim((string) $this->argument('source'), '/\\');
        $disk = Storage::disk('public');
        $missing = [];
        $copied = 0;

        foreach (PageBlocksSeeder::mediaPaths() as $path) {
            $file = $source.'/'.substr($path, strlen(PageBlocksSeeder::MEDIA_PREFIX));

            if ($disk->exists($path)) {
                continue;
            }

            if (! is_file($file)) {
                $missing[] = $path;

                continue;
            }

            $disk->put($path, file_get_contents($file));
            $copied++;
        }

        $this->info("{$copied} عکس کپی شد.");

        foreach ($missing as $path) {
            $this->error("پیدا نشد: {$path}");
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }
}

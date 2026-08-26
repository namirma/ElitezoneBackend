<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Storage;

/**
 * سنِ آخرین نسخهٔ پشتیبان.
 *
 * `backup:monitor` همین را می‌سنجد ولی فقط از طریق اعلان خبر می‌دهد؛ اینجا همان
 * واقعیت به شکلی درمی‌آید که صفحهٔ سلامت بتواند نشانش دهد. بدونِ دیدنِ سنِ آخرین
 * نسخه، خرابیِ بی‌صدای دامپ تا روزِ بازگردانی معلوم نمی‌شود — و آن روز دیر است.
 *
 * عمداً محتویاتِ آرشیو خوانده نمی‌شود؛ دامپ شاملِ کدملی، شبا و شمارهٔ کارت است و
 * این سرویس فقط ابرداده (نام، حجم، زمان) را لمس می‌کند.
 */
final class BackupHealthService
{
    /**
     * @return array{
     *     configured: bool,
     *     disk: string,
     *     latest: array{name: string, size_mb: float, age_hours: int}|null,
     *     count: int,
     *     healthy: bool,
     *     problems: array<int, string>
     * }
     */
    public function report(): array
    {
        $disks = (array) config('backup.backup.destination.disks', []);
        $disk = (string) ($disks[0] ?? '');
        $folder = (string) config('backup.backup.name', 'elitezone');

        if ($disk === '') {
            return $this->emptyReport($disk, ['هیچ دیسکی برای پشتیبان‌گیری تنظیم نشده (BACKUP_DISKS).'], configured: false);
        }

        try {
            $files = collect(Storage::disk($disk)->files($folder))
                ->filter(fn (string $path) => str_ends_with($path, '.zip'));
        } catch (\Throwable $e) {
            // دیسکِ تنظیم‌نشده یا غیرقابل‌دسترس نباید کلِ صفحهٔ سلامت را بیندازد —
            // خودِ همین وضعیت یک یافته است، نه یک خطای برنامه.
            return $this->emptyReport($disk, ["دیسک «{$disk}» قابل خواندن نیست: ".$e->getMessage()], configured: false);
        }

        if ($files->isEmpty()) {
            // ⚠️ اینجا پیکربندی **درست است** — دیسک تنظیم شده و خوانده می‌شود، فقط
            // هنوز نسخه‌ای گرفته نشده. تا نشست ۲۲ این حالت هم `configured: false`
            // برمی‌گرداند و پنل می‌گفت «پشتیبان‌گیری پیکربندی نشده»، که ادمین را
            // دنبال یک مشکلِ پیکربندیِ ناموجود می‌فرستاد. حالتِ واقعی این است:
            // «پیکربندی شده، ولی هرگز اجرا نشده» — اقدامِ لازمش کاملاً متفاوت است.
            return $this->emptyReport(
                $disk,
                ['هیچ نسخهٔ پشتیبانی روی دیسک پیدا نشد — `backup:run --only-db` هرگز موفق نبوده.'],
                configured: true,
            );
        }

        $latestPath = $files
            ->sortByDesc(fn (string $path) => Storage::disk($disk)->lastModified($path))
            ->first();

        $ageHours = (int) floor((time() - Storage::disk($disk)->lastModified($latestPath)) / 3600);
        $maxAge = (int) config('ops.backup.max_age_hours');

        $problems = [];

        if ($ageHours > $maxAge) {
            $problems[] = sprintf(
                'آخرین نسخهٔ پشتیبان %d ساعت پیش گرفته شده (آستانه %d) — زمان‌بندی یا دامپ خراب است.',
                $ageHours,
                $maxAge,
            );
        }

        return [
            'configured' => true,
            'disk' => $disk,
            'latest' => [
                'name' => basename($latestPath),
                'size_mb' => round(Storage::disk($disk)->size($latestPath) / 1_048_576, 2),
                'age_hours' => $ageHours,
            ],
            'count' => $files->count(),
            'healthy' => $problems === [],
            'problems' => $problems,
        ];
    }

    /**
     * @param  array<int, string>  $problems
     * @return array{configured: bool, disk: string, latest: null, count: int, healthy: bool, problems: array<int, string>}
     */
    private function emptyReport(string $disk, array $problems, bool $configured): array
    {
        return [
            // «پیکربندی شده؟» و «نسخه‌ای دارد؟» دو پرسشِ جدا هستند. count === 0
            // به‌تنهایی می‌گوید نسخه‌ای نیست؛ configured نباید همان را تکرار کند.
            'configured' => $configured,
            'disk' => $disk,
            'latest' => null,
            'count' => 0,
            'healthy' => false,
            'problems' => $problems,
        ];
    }
}

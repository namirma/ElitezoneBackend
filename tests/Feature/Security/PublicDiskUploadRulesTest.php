<?php

namespace Tests\Feature\Security;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * هیچ آپلودی که روی دیسک `public` می‌نشیند نباید svg بپذیرد.
 *
 * SVG می‌تواند <script> داشته باشد. با FILESYSTEM_PUBLIC_DRIVER=local — که
 * پیش‌فرضِ .env.example است — فایل از مبدأِ خودِ اپ سرو می‌شود و تبدیل به XSS
 * ذخیره‌شده می‌شود؛ CSP هم `script-src 'self'` دارد و جلویش را نمی‌گیرد.
 *
 * این تست قبلاً یک لیستِ هاردکدشده‌ی ۱۲ فایل بود، نه یک جاروبِ واقعی: فایلی
 * که در لیست نبود اصلاً چک نمی‌شد (`AdminPageBlockController::media()` — یک
 * اندپوینتِ آپلودِ مدیای جدید — همین امروز از زیرِ آن رد شد و فقط چون درست
 * نوشته شده بود سبز ماند)، و `file_exists()` باعث می‌شد جابه‌جاییِ یک فایل
 * بی‌سروصدا از پوشش خارجش کند. حالا کل درختِ `app/` را می‌گردد.
 */
class PublicDiskUploadRulesTest extends TestCase
{
    /**
     * کفِ انتظار: همین امروز جاروب باید حداقل این تعداد «فایلِ قاعده‌ی mimes
     * که به دیسکِ public مرتبط است» پیدا کند (شمارشِ واقعی امروز: ۱۳ — هم
     * فایل‌هایی که خودشان هم store(...,'public') دارند هم قاعده، هم
     * FormRequest/DTOهایی که قاعده در آن‌هاست و مصرف‌کننده‌شان در جای دیگر
     * public می‌نویسد). کف را کمی پایین‌تر (۱۰) گذاشته‌ایم تا یک ادغامِ
     * بی‌ضررِ دو FormRequest تست را نشکند، ولی اگر regex بشکند و صفر برگرداند
     * (دقیقاً همان شکستِ خاموشی که لیستِ هاردکدشده‌ی قبلی داشت)، این ادعا رد
     * می‌شود و تست به‌جای سکوت فریاد می‌زند.
     */
    private const MIN_EXPECTED_PUBLIC_MIME_SITES = 10;

    #[Test]
    public function no_public_disk_upload_rule_accepts_svg(): void
    {
        $sources = $this->collectPhpSources(app_path());

        // مرحله‌ی ۱: کدام فایل‌ها جایی به دیسکِ public می‌نویسند؟
        $publicWriters = [];
        foreach ($sources as $relative => $contents) {
            if ($this->writesToDisk($contents, 'public')) {
                $publicWriters[$relative] = true;
            }
        }

        // مرحله‌ی ۲: کدام فایل‌ها قاعده‌ی mimes دارند؟
        $mimesByFile = [];
        foreach ($sources as $relative => $contents) {
            if (preg_match_all('/mimes:([a-z0-9,]+)/i', $contents, $matches)) {
                $mimesByFile[$relative] = $matches[1];
            }
        }

        $offenders = [];
        $publicMimeSites = 0;

        foreach ($mimesByFile as $relative => $lists) {
            // خودِ همین فایل هم store(..,'public') دارد؟ (مثل AdminProductController)
            // یا فایلی که به public می‌نویسد این کلاس را import و به‌عنوانِ
            // FormRequest/DTO مصرف می‌کند؟ (مثل StoreBadgeRequest ← AdminBadgeController)
            $isPublic = isset($publicWriters[$relative])
                || $this->consumedByPublicWriter($relative, $sources[$relative], $sources, $publicWriters);

            if (! $isPublic) {
                // قاعده‌ی mimes روی یک آپلودِ دیسکِ خصوصی (local/financial) — بی‌خطر.
                continue;
            }

            $publicMimeSites++;

            foreach ($lists as $list) {
                if (in_array('svg', explode(',', strtolower($list)), true)) {
                    $offenders[] = $relative.' → mimes:'.$list;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_PUBLIC_MIME_SITES,
            $publicMimeSites,
            "جاروب فقط {$publicMimeSites} سایتِ آپلودِ دیسکِ public پیدا کرد؛ ".
                'حداقل '.self::MIN_EXPECTED_PUBLIC_MIME_SITES.' سایت انتظار می‌رفت. '.
                'یعنی الگوی regex شکسته و به‌جای هشدار دادن، بی‌صدا خالی برمی‌گردد.'
        );

        $this->assertSame(
            [],
            $offenders,
            "این قواعدِ آپلود روی دیسکِ public می‌نشینند و svg هم می‌پذیرند:\n - "
                .implode("\n - ", $offenders)
        );
    }

    /**
     * تمامِ فایل‌های php زیرِ `app/` را می‌خواند (نه vendor/، نه کلِ ریشه —
     * سریع بماند). کلید آرایه مسیرِ نسبی برای پیام‌های خطا، مقدار محتوای فایل.
     *
     * @return array<string, string>
     */
    private function collectPhpSources(string $root): array
    {
        $out = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            $relative = 'app'.DIRECTORY_SEPARATOR.ltrim(substr($file->getPathname(), strlen($root)), '\\/');
            $out[$relative] = $contents;
        }

        return $out;
    }

    /**
     * آیا این فایل جایی `->store(...)` / `->storeAs(...)` (یا
     * `Storage::disk('x')->put*(`، یا آرایه‌ی `['disk' => 'x']`) با دیسکِ
     * لفظیِ `$disk` صدا می‌زند؟
     *
     * محدودیتِ شناخته‌شده: فقط رشته‌ی *لفظی* را می‌بیند. اگر دیسک از یک
     * ثابت/متغیر بیاید (مثل فرضیِ `Model::DISK`) این regex آن را نمی‌بیند.
     * امروز در کدبیس تنها چنین ثابتی `TicketFile::DISK = 'local'` است —
     * یعنی خصوصی — پس این نقطه‌کور امروز چیزی را از قلم نمی‌اندازد؛ اگر
     * فردا یک آپلودِ عمومی از این راه دیسک را انتخاب کرد، باید این متد را
     * برای آن ثابت هم آگاه کرد.
     */
    private function writesToDisk(string $contents, string $disk): bool
    {
        $quoted = preg_quote($disk, '/');

        return (bool) preg_match('/->store(?:As)?\([^)]*[\'"]'.$quoted.'[\'"]\s*\)/', $contents)
            || (bool) preg_match('/Storage::disk\([\'"]'.$quoted.'[\'"]\)\s*->\s*put(?:File(?:As)?)?\(/', $contents)
            || (bool) preg_match('/[\'"]disk[\'"]\s*=>\s*[\'"]'.$quoted.'[\'"]/', $contents);
    }

    /**
     * وقتی قاعده‌ی mimes در یک فایلِ مجزا است (FormRequest یا DTO)، نه در
     * همان فایلی که `store()` را صدا می‌زند: کلاسِ این فایل را پیدا کن و
     * ببین آیا فایلِ دیگری که به دیسکِ public می‌نویسد آن را import کرده و
     * به‌عنوانِ type-hint (پارامتر) یا `new ClassName(` مصرف می‌کند یا نه.
     * دقیقاً همان جفتی که کدبیس واقعاً دارد: StoreBadgeRequest (قاعده) ←
     * AdminBadgeController::store() (ذخیره‌سازی).
     *
     * تصمیمِ آگاهانه: تفکیک در سطحِ *فایل* است، نه *متد*. اگر یک کنترلر هم
     * به public و هم به یک دیسکِ خصوصی بنویسد، هر قاعده‌ی mimesِ مرتبط با آن
     * فایل محافظه‌کارانه public در نظر گرفته می‌شود — یعنی در چنان حالتی این
     * تست ممکن است یک آپلودِ خصوصی را هم پرچم بزند. امروز در کدبیس تأیید
     * شده که چنین فایلی وجود ندارد (هر کنترلر/FormRequestِ آپلود دقیقاً یک
     * دیسک دارد — گزارش را ببینید)؛ اگر این تغییر کرد، باید این متد به سطحِ
     * «متد» (نه فایل) دقیق‌تر شود.
     *
     * @param  array<string, string>  $allSources
     * @param  array<string, true>  $publicWriters
     */
    private function consumedByPublicWriter(
        string $ownRelative,
        string $ownContents,
        array $allSources,
        array $publicWriters
    ): bool {
        if (! preg_match('/\bclass\s+(\w+)/', $ownContents, $classMatch)) {
            return false;
        }

        $shortName = $classMatch[1];
        $quoted = preg_quote($shortName, '/');

        foreach (array_keys($publicWriters) as $writerRelative) {
            if ($writerRelative === $ownRelative) {
                continue;
            }

            $writerContents = $allSources[$writerRelative];

            $imports = (bool) preg_match('/\buse\s+[^;]*'.$quoted.'\s*;/', $writerContents);
            $usedAsTypeOrNew = (bool) preg_match('/\b'.$quoted.'\s*\$\w+/', $writerContents)
                || (bool) preg_match('/\bnew\s+'.$quoted.'\s*\(/', $writerContents);

            if ($imports && $usedAsTypeOrNew) {
                return true;
            }
        }

        return false;
    }
}

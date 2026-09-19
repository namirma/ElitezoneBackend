<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هیچ آپلودی که روی دیسک `public` می‌نشیند نباید svg بپذیرد.
 *
 * SVG می‌تواند <script> داشته باشد. با FILESYSTEM_PUBLIC_DRIVER=local — که
 * پیش‌فرضِ .env.example است — فایل از مبدأِ خودِ اپ سرو می‌شود و تبدیل به XSS
 * ذخیره‌شده می‌شود؛ CSP هم `script-src 'self'` دارد و جلویش را نمی‌گیرد.
 *
 * سه مسیر جا مانده بودند و یکی‌شان (SupplierFactoryController) اصلاً ادمین‌محور
 * نبود — هر تأمین‌کننده‌ی تأییدشده به آن دسترسی دارد.
 */
class PublicDiskUploadRulesTest extends TestCase
{
    /** فایل‌هایی که هم `store(..., 'public')` دارند و هم قواعد mimes. */
    private const SOURCES = [
        'Http/Controllers/Api/User/SupplierFactoryController.php',
        'Http/Controllers/Api/Admin/AdminFactoryController.php',
        'Http/Controllers/Api/Admin/AdminProductCategoryController.php',
        'Http/Controllers/Api/Admin/AdminProductController.php',
        'Http/Controllers/Api/User/AvatarController.php',
        'Http/Requests/Admin/StoreBadgeRequest.php',
        'Http/Requests/Admin/UpdateBadgeRequest.php',
        'Http/Requests/Admin/StoreBannerRequest.php',
        'Http/Requests/Admin/UpdateBannerRequest.php',
        'Http/Requests/Admin/StoreArticleRequest.php',
        'Http/Requests/Admin/UpdateArticleRequest.php',
        'Http/Requests/Profile/StoreAvatarRequest.php',
    ];

    #[Test]
    public function no_public_disk_upload_rule_accepts_svg(): void
    {
        $offenders = [];

        foreach (self::SOURCES as $relative) {
            $path = app_path($relative);

            if (! file_exists($path)) {
                continue;
            }

            preg_match_all('/mimes:([a-z0-9,]+)/i', file_get_contents($path), $matches);

            foreach ($matches[1] as $list) {
                if (in_array('svg', explode(',', strtolower($list)), true)) {
                    $offenders[] = $relative.' → mimes:'.$list;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این قواعد آپلود هنوز svg می‌پذیرند در حالی که فایل روی دیسک public می‌نشیند:\n - "
                .implode("\n - ", $offenders)
        );
    }
}

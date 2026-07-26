<?php

namespace Tests\Feature\Product;

use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\User\ProductReviewController;
use App\Models\ProductReview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ماژول «نظر و امتیاز محصول» در محصولِ فعلی EliteZone وجود ندارد (تأیید محصول،
 * ۴ مرداد ۱۴۰۵)، ولی کدش کامل است. به‌جای حذف، فقط ثبتِ روت‌ها پشت
 * `config('features.product_reviews')` رفت تا برگشت‌پذیر بماند.
 *
 * روت‌ها هنگام boot ثبت می‌شوند، پس با `config()` داخل تست نمی‌شود حالتِ خاموش
 * را شبیه‌سازی کرد (phpunit.xml فلگ را روشن می‌کند تا خودِ ماژول تست‌شده بماند).
 * بنابراین اینجا *وجودِ گیت* را روی متنِ فایل‌های روت pin می‌کنیم — همان الگویی
 * که ReAuditFix3Test برای گیت‌های مجوزِ ادمین استفاده می‌کند.
 */
class ProductReviewRoutesAreGatedTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_feature_flag_defaults_to_off(): void
    {
        // در تست‌ها phpunit.xml فلگ را روشن می‌کند (تا خودِ ماژول تست‌شده بماند)،
        // پس نمی‌شود مقدارِ resolve‌شده را چک کرد — env() از $_ENV/$_SERVER
        // می‌خواند و با putenv() پاک نمی‌شود. چیزی که واقعاً اهمیت دارد این است
        // که *پیش‌فرضِ نوشته‌شده در config* خاموش باشد، چون پروداکشن این env را
        // ندارد. پس همان را pin می‌کنیم.
        $src = file_get_contents(base_path('config/features.php'));

        $this->assertMatchesRegularExpression(
            "/'product_reviews'\s*=>\s*\(bool\)\s*env\(\s*'FEATURE_PRODUCT_REVIEWS'\s*,\s*false\s*\)/",
            $src,
            'پیش‌فرضِ features.product_reviews باید false باشد تا روی پروداکشن خاموش بماند.',
        );

        $this->assertArrayHasKey('product_reviews', require base_path('config/features.php'));
    }

    #[Test]
    public function every_review_route_group_sits_behind_the_flag(): void
    {
        $files = [
            'routes/api/public.php' => "/reviews',",
            'routes/api/user.php' => 'reviews',
            'routes/api/admin.php' => "prefix('reviews')",
        ];

        foreach ($files as $path => $needle) {
            $src = file_get_contents(base_path($path));

            $this->assertStringContainsString(
                $needle,
                $src,
                "{$path}: روت نظرات پیدا نشد — اگر جابه‌جا شده، این تست را به‌روز کنید."
            );

            $this->assertStringContainsString(
                "config('features.product_reviews')",
                $src,
                "{$path}: روت نظرات باید پشت فلگ features.product_reviews باشد."
            );
        }
    }

    #[Test]
    public function the_module_itself_is_still_intact(): void
    {
        // حذف نشده — فقط خاموش. اگر روزی روشن شد باید همه‌چیز سر جایش باشد.
        $this->assertTrue(class_exists(ProductReview::class));
        $this->assertTrue(class_exists(ProductReviewController::class));
        $this->assertTrue(class_exists(AdminReviewController::class));
        $this->assertTrue(Schema::hasTable('product_reviews'));
    }
}

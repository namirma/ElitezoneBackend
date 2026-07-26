<?php

namespace Tests\Feature\Order;

use App\Http\Controllers\Api\User\OrderSurveyController;
use App\Http\Resources\Order\OrderSurveyResource;
use App\Models\OrderSurvey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * نظرسنجی سفارش (اسناد S و T سند طراحی) در محصولِ فعلی EliteZone وجود ندارد
 * — تأیید محصول، ۴ مرداد ۱۴۰۵ — ولی کدش کامل است. مثل ماژول نظرِ محصول،
 * به‌جای حذف فقط ثبتِ روت‌ها پشت `config('features.order_surveys')` رفت.
 *
 * روت‌ها هنگام boot ثبت می‌شوند، پس با `config()` داخل تست نمی‌شود حالتِ خاموش
 * را شبیه‌سازی کرد (phpunit.xml فلگ را روشن می‌کند تا خودِ ماژول تست‌شده بماند).
 * بنابراین اینجا *وجودِ گیت* روی متنِ فایل روت pin می‌شود — همان الگوی
 * ProductReviewRoutesAreGatedTest و ReAuditFix3Test.
 */
class OrderSurveyRoutesAreGatedTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_feature_flag_defaults_to_off(): void
    {
        $src = file_get_contents(base_path('config/features.php'));

        $this->assertMatchesRegularExpression(
            "/'order_surveys'\s*=>\s*\(bool\)\s*env\(\s*'FEATURE_ORDER_SURVEYS'\s*,\s*false\s*\)/",
            $src,
            'پیش‌فرضِ features.order_surveys باید false باشد تا روی پروداکشن خاموش بماند.',
        );

        $this->assertArrayHasKey('order_surveys', require base_path('config/features.php'));
    }

    #[Test]
    public function both_survey_routes_sit_behind_the_flag(): void
    {
        $src = file_get_contents(base_path('routes/api/user.php'));

        foreach (["'/{order}/survey'", "'/surveys'"] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $src,
                "روت {$needle} پیدا نشد — اگر جابه‌جا شده، این تست را به‌روز کنید."
            );
        }

        // هر دو روت باید داخل یک بلاکِ فلگ باشند.
        $this->assertSame(
            2,
            substr_count($src, "config('features.order_surveys')"),
            'هر دو روتِ نظرسنجی باید جداگانه پشت فلگ باشند.',
        );
    }

    #[Test]
    public function the_module_itself_is_still_intact(): void
    {
        // حذف نشد — فقط خاموش. اگر روزی روشن شد باید همه‌چیز سر جایش باشد.
        $this->assertTrue(class_exists(OrderSurvey::class));
        $this->assertTrue(class_exists(OrderSurveyController::class));
        $this->assertTrue(class_exists(OrderSurveyResource::class));
        $this->assertTrue(Schema::hasTable('order_surveys'));
    }
}

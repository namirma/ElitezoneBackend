<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/cors.php تنها منبعِ حقیقتِ CORS است.
 *
 * قبلاً یک میدل‌ورِ دستیِ دوم (App\Http\Middleware\Cors) با allowlistِ هاردکد
 * روی همهٔ روت‌های API سوار بود و چون هدر را در مسیرِ خروج بی‌قید می‌نوشت،
 * مقدارِ HandleCors را بازنویسی می‌کرد. نتیجه: روی پروداکشن هم
 * `http://localhost:5173` با Allow-Credentials مجاز بود — دقیقاً همان چیزی که
 * config/cors.php آن را پشتِ APP_ENV=local گِیت کرده.
 *
 * این تست هم دروازه را pin می‌کند و هم برنگشتنِ آن میدل‌ور را.
 */
class CorsAllowlistTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_dev_origin_is_not_allowed_outside_the_local_environment(): void
    {
        // محیط تست local نیست، پس مبدأهای localhost نباید در allowlist باشند.
        $this->assertNotContains('http://localhost:5173', config('cors.allowed_origins'));

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->getJson('/api/shipping-methods');

        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'یک مبدأ خارج از allowlist نباید هدر Access-Control-Allow-Origin بگیرد.'
        );
    }

    #[Test]
    public function a_configured_production_origin_is_allowed(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://elitezone.me'])
            ->getJson('/api/shipping-methods');

        $this->assertSame(
            'https://elitezone.me',
            $response->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function the_hand_rolled_cors_middleware_is_gone(): void
    {
        // با file_exists چک می‌شود نه class_exists — نقشهٔ classmap کامپوزر ممکن است
        // هنوز مدخلِ حذف‌شده را داشته باشد و class_exists را به include شکست‌خورده بکشاند.
        $this->assertFileDoesNotExist(
            app_path('Http/Middleware/Cors.php'),
            'App\Http\Middleware\Cors باید حذف بماند — allowlist دوم باعث دور زدن config/cors.php می‌شود.'
        );

        $this->assertStringNotContainsString(
            "'cors'",
            file_get_contents(app_path('Providers/RouteServiceProvider.php')),
            'روت‌های API نباید هیچ میدل‌ورِ cors سطح-روت داشته باشند.'
        );
    }
}

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

        // جاییکه میدل‌ور ثبت می‌شود بین دو برنچ فرق دارد: روی L10 در
        // RouteServiceProvider و Http/Kernel، روی L13 در bootstrap/app.php.
        // هرکدام که وجود داشت نباید هیچ ارجاعی به cors داشته باشد.
        $wiringFiles = array_filter([
            app_path('Providers/RouteServiceProvider.php'),
            app_path('Http/Kernel.php'),
            base_path('bootstrap/app.php'),
        ], 'file_exists');

        $this->assertNotEmpty($wiringFiles, 'هیچ فایلِ ثبتِ میدل‌ور پیدا نشد.');

        foreach ($wiringFiles as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/'cors'|Middleware\\\\Cors|\bCors::class/",
                $this->codeWithoutComments($file),
                basename($file).' هنوز به میدل‌ورِ cors ارجاع می‌دهد — CORS فقط باید از config/cors.php بیاید.'
            );
        }
    }

    /**
     * سورس بدونِ کامنت — این فایل‌ها کامنت‌های توضیحی دارند که *چرا* میدل‌ورِ
     * cors حذف شد، و همان توضیح شاملِ نامِ کلاس است. تست باید کد را ببیند نه متن.
     */
    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}

<?php

namespace App\Traits\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

trait HasCircuitBreaker
{
    protected int $cbThreshold = 5;
    protected int $cbTimeout = 60;
    protected int $cbFailureTtl = 300;

    /**
     * متد اصلی برای اجرای کدهای حساس با محافظت
     *
     * @param string $serviceKey
     * @param callable $callback
     * @return mixed
     * @throws Exception
     */
    protected function executeProtected(string $serviceKey, callable $callback)
    {
        // ۱. ابتدا چک می‌کنیم فیوز باز است یا نه
        if ($this->isCircuitOpen($serviceKey)) {
            Log::warning("Circuit Breaker BLOCKED request to: {$serviceKey}");
            throw new Exception("سرویس {$serviceKey} موقتاً در دسترس نیست. لطفاً دقایقی دیگر تلاش کنید.");
        }

        try {
            // ۲. اجرای عملیات اصلی
            $result = $callback();

            // ۳. اگر موفق بود، شمارنده خطاها را صفر کن
            $this->recordSuccess($serviceKey);

            return $result;
        } catch (Exception $e) {
            // ۴. اگر شکست خورد، خطا را ثبت کن
            $this->recordFailure($serviceKey);

            // خطا را دوباره پرتاب کن تا لایه‌های بالاتر خبردار شوند
            throw $e;
        }
    }

    /**
     * بررسی باز بودن فیوز (آیا سرویس قطع است؟)
     */
    protected function isCircuitOpen(string $serviceKey): bool
    {
        return Cache::has("circuit:{$serviceKey}:open");
    }

    /**
     * ثبت خطا و بررسی آستانه برای باز کردن فیوز
     */
    protected function recordFailure(string $serviceKey): void
    {
        $failKey = "circuit:{$serviceKey}:failures";
        $openKey = "circuit:{$serviceKey}:open";

        // افزایش تعداد خطاها
        $failures = Cache::increment($failKey);

        // اگر اولین خطا بود، برای شمارنده انقضا بگذار (که تا ابد نماند)
        if ($failures === 1) {
            Cache::expire($failKey, $this->cbFailureTtl);
        }

        // اگر از حد مجاز رد شد، فیوز را باز کن (قطع کن)
        if ($failures >= $this->cbThreshold) {
            Cache::put($openKey, true, $this->cbTimeout); // فیوز باز شد
            Cache::forget($failKey); // ریست شمارنده

            Log::critical("Circuit Breaker OPENED for service: {$serviceKey}. Too many failures.");
        }
    }

    /**
     * ثبت موفقیت (ریست وضعیت به حالت نرمال)
     */
    protected function recordSuccess(string $serviceKey): void
    {
        Cache::forget("circuit:{$serviceKey}:failures");
    }
}

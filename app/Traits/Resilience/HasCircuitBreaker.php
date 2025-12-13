<?php

namespace App\Traits\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Exception;

trait HasCircuitBreaker
{
    /**
     * تنظیمات پیش‌فرض (قابل بازنویسی در کلاسی که از این تریت استفاده می‌کند)
     */
    protected int $cbThreshold = 5;      // تعداد خطای مجاز
    protected int $cbTimeout = 60;       // مدت زمان قطع بودن سرویس (ثانیه)
    protected int $cbFailureTtl = 300;   // بازه زمانی شمارش خطاها (ثانیه)

    /**
     * اجرای کد محافظت شده
     *
     * @param string $serviceKey نام یکتا برای سرویس (مثلاً 'sms_provider')
     * @param callable $callback تابعی که باید اجرا شود
     * @return mixed
     * @throws Throwable
     */
    protected function executeProtected(string $serviceKey, callable $callback)
    {
        // ۱. بررسی وضعیت مدار (آیا فیوز پریده؟)
        if ($this->isCircuitOpen($serviceKey)) {
            Log::warning("Circuit Breaker BLOCKED request to: {$serviceKey}");
            // پرتاب خطا بدون اینکه اصلاً درخواست به سرویس خارجی ارسال شود (Fail Fast)
            throw new Exception("سرویس {$serviceKey} موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.");
        }

        try {
            // ۲. تلاش برای اجرا
            $result = $callback();

            // ۳. در صورت موفقیت، شمارنده خطا ریست می‌شود
            $this->recordSuccess($serviceKey);

            return $result;

        } catch (Throwable $e) {
            // ۴. در صورت شکست، خطا ثبت می‌شود
            $this->recordFailure($serviceKey);

            // خطا را به لایه بالاتر پاس می‌دهیم تا کنترلر متوجه شود
            throw $e;
        }
    }

    /**
     * آیا مدار باز است؟ (یعنی سرویس قطع شده است)
     */
    protected function isCircuitOpen(string $serviceKey): bool
    {
        return Cache::has("circuit:{$serviceKey}:open");
    }

    /**
     * ثبت خطا و تصمیم‌گیری برای قطع کردن مدار
     */
    protected function recordFailure(string $serviceKey): void
    {
        $failKey = "circuit:{$serviceKey}:failures";
        $openKey = "circuit:{$serviceKey}:open";

        if (!Cache::add($failKey, 1, $this->cbFailureTtl)) {
            // اگر کلید بود، یکی اضافه کن
            Cache::increment($failKey);
        }

        // خواندن تعداد فعلی خطاها
        $failures = Cache::get($failKey, 1);

        // اگر از حد آستانه رد شد، مدار را باز کن (فیوز بپرد)
        if ($failures >= $this->cbThreshold) {
            Cache::put($openKey, true, $this->cbTimeout); // مدار برای ۶۰ ثانیه باز می‌ماند
            Cache::forget($failKey); // شمارنده خطا را پاک کن تا بعد از ۶۰ ثانیه از صفر شروع کند

            Log::critical("Circuit Breaker OPENED for service: {$serviceKey}. Too many failures.");
        }
    }

    /**
     * ثبت موفقیت
     */
    protected function recordSuccess(string $serviceKey): void
    {
        // اگر عملیات موفق بود، فرض می‌کنیم سرویس سالم است و شمارنده خطا را پاک می‌کنیم
        // (این استراتژی باعث می‌شود فقط خطاهای "پشت سر هم" باعث قطع مدار شوند)
        Cache::forget("circuit:{$serviceKey}:failures");
    }
}

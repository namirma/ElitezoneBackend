<?php

namespace App\Services\PodiumServices\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * ترجمه‌ی خطاهای سرویسِ بیرونیِ Podium به یک پاسخ HTTP تمیز.
 *
 * قاعده‌ی پروژه: «هرگز پیام خام سرویس بیرونی را به کلاینت برنگردان» — جزئیات فنی
 * (TLS/DNS/timeout، نام متغیر env، کد خطای Podium) فقط لاگ می‌شود و کاربر یک پیام
 * فارسیِ قابل‌فهم می‌بیند. بدون این لایه، استثنای خام تا هندلر سراسری بالا می‌رفت و
 * پنل چیزی مثل «cURL error 35: TLS connect error … https://api.pod.ir/…» نشان می‌داد.
 */
trait TranslatesPodiumFailures
{
    /**
     * فراخوانی محافظت‌شده‌ی سرویس Podium.
     *
     * @param  string  $context  نام سرویس برای لاگ (مثلاً 'balances')
     */
    protected function guardPodiumCall(string $context, callable $callback): array
    {
        try {
            return $callback();
        } catch (ServiceUnavailableHttpException $e) {
            throw $e; // از قبل ترجمه‌شده
        } catch (ConnectionException $e) {
            $this->failPodium($context, 'connection', $e->getMessage(), 'podium.service_unavailable');
        } catch (RequestException $e) {
            $this->failPodium($context, 'http', $e->getMessage(), 'podium.service_error');
        } catch (Throwable $e) {
            // خطاهای پیکربندی/اسکیمای خودمان (RuntimeException) هم نباید متن فنی
            // (نام کلید env، کد خطای سرویس) را به کاربر نشان دهند.
            $this->failPodium($context, 'internal', $e->getMessage(), 'podium.service_error');
        }
    }

    private function failPodium(string $context, string $kind, string $detail, string $langKey): never
    {
        Log::error("[Podium][{$context}] {$kind} failure", ['detail' => $detail]);

        throw new ServiceUnavailableHttpException(null, __($langKey));
    }
}

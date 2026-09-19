<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ops\SystemHealthService;
use Illuminate\Http\JsonResponse;

/**
 * صفحه‌ی سلامتِ سامانه — یک اندپوینت که می‌گوید همه‌چیز سرِ جایش هست یا نه.
 *
 * چهار خرابیِ خاموشِ پروژه را یک‌جا نشان می‌دهد: صف، پشتیبان، دفترِ مالی و
 * سفارش‌های گیرکرده. هیچ‌کدام خودشان خطا تولید نمی‌کنند، پس تنها راهِ دیدنشان
 * پرسیدن است.
 *
 * پاسخ همیشه ۲۰۰ است و حکمِ سلامت در `data.healthy` می‌آید — نه در کدِ HTTP.
 * این اندپوینت پشتِ `auth:sanctum` و مجوزِ ادمین است، پس یک uptime-checkِ بیرونی
 * اصلاً نمی‌تواند صدایش بزند؛ برگرداندنِ ۵۰۳ فقط قراردادِ {status,message,data}
 * پروژه را می‌شکست بی‌آنکه کسی از آن کد استفاده کند. برای هشدارِ ماشینی،
 * `queue:health` و `financial:doctor` هستند که exit code می‌دهند.
 *
 * فقط خواندنی است و هیچ عملیاتی اجرا نمی‌کند.
 */
class AdminSystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        $report = $health->report();

        return success($report, $report['healthy']
            ? 'admin.system_health_ok'
            : 'admin.system_health_degraded');
    }
}

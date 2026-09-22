<?php

namespace App\Http\Middleware;

use App\Services\Profile\EliteYARVerificationMethods;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `verification.method:{education|job}` — نوشتنِ مدرک/سابقه فقط وقتی آن روش
 * در تنظیماتِ ادمین روشن است.
 *
 * سایت ردیفِ روشِ خاموش را «به‌زودی» نشان می‌دهد و صفحه‌اش را نمی‌سازد؛ ولی
 * اگر API باز بماند، یک درخواستِ دستی می‌تواند مدرکی بفرستد که به صفِ ادمین
 * می‌رسد و به هیچ تأییدی نمی‌انجامد. خواندن و حذف عمداً باز می‌مانند تا
 * رکوردهای قدیمیِ کاربر دیده و پاک شوند.
 *
 * ۴۲۲ نه ۴۰۴: روت ثبت شده و کاربر مجاز است؛ فقط این روش فعلاً پذیرفته نمی‌شود.
 */
class EnsureVerificationMethodEnabled
{
    public function handle(Request $request, Closure $next, string $method): Response
    {
        if (EliteYARVerificationMethods::isEnabled($method)) {
            return $next($request);
        }

        return error(__('elite_yar.method_disabled'), [], 422);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تأمین‌کننده از سایت خرید نمی‌کند (تصمیمِ علی ۱۴۰۵/۰۶/۲۸، اسپکِ یکپارچه‌سازی
 * §۴-۲): حسابِ تأمین‌کننده حسابِ فروش است؛ برای خرید حسابِ جداگانه‌ای می‌سازد.
 *
 * هم نقشِ `supplier` (تأییدشده) و هم نوعِ `supplier` (متقاضی) بسته‌اند، چون
 * انتخابِ نقشِ تأمین‌کننده با بقیه‌ی نقش‌ها ناسازگار است و کاربر خودش این
 * حساب را «تأمین‌کننده» اعلام کرده. مهمان و بقیه‌ی نقش‌ها رد می‌شوند.
 */
class EnsureUserIsNotSupplier
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user && self::isSupplierAccount($user)) {
            return error(__('auth.policy.supplier_cannot_buy'), [], 403);
        }

        return $next($request);
    }

    public static function isSupplierAccount(User $user): bool
    {
        if ($user->hasRole('supplier')) {
            return true;
        }

        return $user->type?->getAttribute('slug') === 'supplier';
    }
}

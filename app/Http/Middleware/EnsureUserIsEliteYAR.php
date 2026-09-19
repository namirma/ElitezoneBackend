<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEliteYAR
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        // یک سیگنال، نه ترکیبِ دو واژگان.
        //
        // این گارد قبلاً `type.slug === 'elite_yar' || hasRole(['engineer','contractor'])`
        // بود، و آن OR یک ضرورت بود نه سلیقه: `engineer`/`contractor` مدرک‌اند و
        // فقط بعد از تأیید مدارک وصل می‌شوند، پس الیت یارِ تازه هیچ نقشی نداشت و
        // بدون شاخه‌ی `type` بیرون می‌ماند. یعنی مجوز از دو جای مستقل خوانده می‌شد
        // که می‌توانستند با هم اختلاف پیدا کنند.
        //
        // حالا `elite_yar` خودش یک نقشِ Laratrust است که همان لحظه‌ی انتخابِ نقش
        // وصل می‌شود (SyncUserRolesAction). قصد از مدرک جدا شد: این نقش یعنی
        // «الیت یار است»، و engineer/contractor یعنی «مدرکش تأیید شده».
        if ($user->hasRole('elite_yar')) {
            return $next($request);
        }

        return error(__('auth.forbidden'), [], 403);
    }
}

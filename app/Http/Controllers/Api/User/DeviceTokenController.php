<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * ثبت و حذفِ اشتراکِ پوشِ دستگاه.
 *
 * مقادیر مستقیماً از `PushSubscription` مرورگر می‌آیند:
 *   `endpoint` ← subscription.endpoint
 *   `public_key` ← keys.p256dh
 *   `auth_key` ← keys.auth
 *
 * اپ در هر بار اجرا `store` را صدا می‌زند (idempotent) و هنگام خروج از حساب
 * `destroy` را.
 */
class DeviceTokenController extends Controller
{
    /**
     * بیشترین اشتراکِ همزمانِ هر کاربر.
     *
     * بدونِ سقف، یک کلاینتِ واردشده می‌تواند هزاران endpointِ جعلی ثبت کند و
     * از آن به بعد **هر** نوتیفیکیشنِ آن کاربر هزاران درخواستِ
     * محکوم‌به‌شکست تولید می‌کند (که چون خطایشان «منقضی» نیست، سه بار هم
     * تلاشِ مجدد می‌شوند). ۱۰ برای موبایل + دسکتاپ + یکی دو مرورگرِ دیگر
     * دست‌ودل‌بازانه است.
     */
    private const MAX_PER_USER = 10;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|max:512|url',
            'public_key' => 'required|string|max:255',
            'auth_key' => 'required|string|max:100',
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
            'app_version' => 'nullable|string|max:20',
        ]);

        // کلیدِ تطبیق `endpoint` است نه (user, endpoint): اشتراک به دستگاه
        // تعلق دارد و اگر کاربرِ دیگری روی همان مرورگر وارد شود، مالکِ ردیف
        // باید عوض شود — وگرنه نوتیفیکیشن‌های کاربرِ قبلی روی دستگاهِ او
        // ظاهر می‌شود.
        DeviceToken::updateOrCreate(
            [DeviceToken::ENDPOINT => $data['endpoint']],
            [
                DeviceToken::USER_ID => Auth::id(),
                DeviceToken::PUBLIC_KEY => $data['public_key'],
                DeviceToken::AUTH_KEY => $data['auth_key'],
                DeviceToken::PLATFORM => $data['platform'],
                DeviceToken::APP_VERSION => $data['app_version'] ?? null,
                DeviceToken::LAST_SEEN_AT => now(),
            ],
        );

        $this->pruneOldest((int) Auth::id());

        return success(null, __('global.operation_successful'));
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:512']);

        // شرطِ مالکیت لازم است: بدون آن هر کاربرِ واردشده‌ای می‌توانست با
        // فرستادنِ endpointِ دیگری، پوشِ او را خاموش کند.
        DeviceToken::where(DeviceToken::ENDPOINT, $data['endpoint'])
            ->where(DeviceToken::USER_ID, Auth::id())
            ->delete();

        return success(null, __('global.operation_successful'));
    }

    /**
     * قدیمی‌ترین اشتراک‌های مازاد را حذف می‌کند. تازه‌ترین‌ها می‌مانند چون
     * دستگاهی که همین حالا ثبت کرده همان است که کاربر دستش است.
     */
    private function pruneOldest(int $userId): void
    {
        $keep = DeviceToken::where(DeviceToken::USER_ID, $userId)
            ->orderByDesc(DeviceToken::LAST_SEEN_AT)
            ->orderByDesc(DeviceToken::ID)
            ->limit(self::MAX_PER_USER)
            ->pluck(DeviceToken::ID);

        DeviceToken::where(DeviceToken::USER_ID, $userId)
            ->whereNotIn(DeviceToken::ID, $keep)
            ->delete();
    }
}

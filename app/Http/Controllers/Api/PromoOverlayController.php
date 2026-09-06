<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Content\PromoOverlayResource;
use App\Models\PromoOverlay;
use App\Services\Content\OverlayAudienceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * اورلی‌هایی که برای **کاربرِ فعلی** واجد شرایط‌اند.
 *
 * مسیر عمومی است (مهمان هم اورلیِ خوش‌آمد را می‌گیرد) ولی وقتی توکن همراه
 * درخواست بیاید، شرط‌های وابسته به کاربر هم ارزیابی می‌شوند.
 *
 * فقط شرطِ «مخاطب» اینجا اعمال می‌شود؛ زمان‌بندی (تأخیر)، مسیر و دوره‌ی تکرار
 * را فرانت اجرا می‌کند — چون هر سه به وضعیتِ مرورگر وابسته‌اند.
 */
class PromoOverlayController extends Controller
{
    public function index(Request $request, OverlayAudienceEvaluator $evaluator): JsonResponse
    {
        // فهرستِ فعال‌ها کش می‌شود (برای همه یکسان است)؛ فیلترِ مخاطب سپس
        // per-user روی همین مجموعه اجرا می‌شود. کش با هر save/delete در مدل
        // پاک می‌شود، پس ویرایشِ ادمین بلافاصله دیده می‌شود.
        $active = Cache::remember(
            PromoOverlay::CACHE_KEY,
            3600,
            fn () => PromoOverlay::query()
                ->where(PromoOverlay::IS_ACTIVE, true)
                ->orderBy(PromoOverlay::PRIORITY)
                ->orderBy(PromoOverlay::ID)
                ->get()
        );

        $user = $request->user();

        $matching = $active->filter(
            fn (PromoOverlay $overlay) => $evaluator->matches($overlay, $user)
        )->values();

        return success(
            PromoOverlayResource::collection($matching),
            __('global.received_successfully')
        );
    }
}

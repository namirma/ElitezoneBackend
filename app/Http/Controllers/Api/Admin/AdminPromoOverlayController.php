<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StorePromoOverlayRequest;
use App\Http\Resources\Content\PromoOverlayResource;
use App\Models\PromoOverlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CRUDِ اورلی‌های ترغیب/تبلیغاتی برای بک‌آفیس.
 *
 * سه اورلیِ سیستمی (`PromoOverlay::SYSTEM_KEYS`) قابلِ **ویرایش** هستند ولی
 * قابلِ **حذف** نیستند: کدِ سایت به وجودشان تکیه ندارد، ولی حذفشان یعنی
 * ناپدید شدنِ جریانِ آنبردینگ بدون اینکه کسی متوجه شود. برای خاموش کردنشان
 * `is_active = false` هست.
 */
class AdminPromoOverlayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PromoOverlay::query();

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if ($request->filled('is_active')) {
            $query->where(PromoOverlay::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(PromoOverlay::TITLE, 'like', "%{$search}%");
        }

        $overlays = $query
            ->orderBy(PromoOverlay::PRIORITY)
            ->orderByDesc(PromoOverlay::ID)
            ->paginate((int) $request->integer('per_page', 20));

        return success(
            PromoOverlayResource::collection($overlays)->response()->getData(true),
            __('global.received_successfully')
        );
    }

    public function show(PromoOverlay $promoOverlay): JsonResponse
    {
        return success(new PromoOverlayResource($promoOverlay), __('global.received_successfully'));
    }

    public function store(StorePromoOverlayRequest $request): JsonResponse
    {
        $data = $this->payload($request);

        // `key` فقط برای اورلی‌های سیستمی است و از API ساخته نمی‌شود.
        unset($data[PromoOverlay::KEY]);

        $overlay = PromoOverlay::create($data);

        activity()
            ->performedOn($overlay)
            ->withProperties(['title' => $overlay->{PromoOverlay::TITLE}])
            ->log('promo_overlay.created');

        return created(new PromoOverlayResource($overlay->refresh()), __('admin.promo_overlays.created'));
    }

    public function update(StorePromoOverlayRequest $request, PromoOverlay $promoOverlay): JsonResponse
    {
        $data = $this->payload($request, $promoOverlay);
        unset($data[PromoOverlay::KEY]);

        $promoOverlay->update($data);

        activity()
            ->performedOn($promoOverlay)
            ->withProperties(['title' => $promoOverlay->{PromoOverlay::TITLE}])
            ->log('promo_overlay.updated');

        return success(new PromoOverlayResource($promoOverlay->refresh()), __('admin.promo_overlays.updated'));
    }

    public function destroy(PromoOverlay $promoOverlay): JsonResponse
    {
        if ($promoOverlay->isSystem()) {
            return error(__('admin.promo_overlays.cannot_delete_system'), [], 422);
        }

        $promoOverlay->delete();

        activity()->performedOn($promoOverlay)->log('promo_overlay.deleted');

        return success(null, __('admin.promo_overlays.deleted'));
    }

    public function restore(int $id): JsonResponse
    {
        $overlay = PromoOverlay::onlyTrashed()->findOrFail($id);
        $overlay->restore();

        return success(new PromoOverlayResource($overlay), __('admin.promo_overlays.restored'));
    }

    public function toggle(PromoOverlay $promoOverlay): JsonResponse
    {
        $promoOverlay->update([
            PromoOverlay::IS_ACTIVE => ! $promoOverlay->{PromoOverlay::IS_ACTIVE},
        ]);

        return success(new PromoOverlayResource($promoOverlay->refresh()), __('admin.promo_overlays.toggled'));
    }

    /**
     * ساختِ payload از درخواست — شاملِ مدیریتِ فایلِ تصویر.
     *
     * @return array<string, mixed>
     */
    private function payload(StorePromoOverlayRequest $request, ?PromoOverlay $existing = null): array
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->hasFile('image')) {
            // تصویرِ قبلی را پاک کن تا دیسک پر از فایلِ یتیم نشود
            if ($existing?->{PromoOverlay::IMAGE}) {
                Storage::disk('public')->delete($existing->{PromoOverlay::IMAGE});
            }
            $data[PromoOverlay::IMAGE] = $request->file('image')->store('promo-overlays', 'public');
        } elseif ($request->boolean('remove_image') && $existing?->{PromoOverlay::IMAGE}) {
            Storage::disk('public')->delete($existing->{PromoOverlay::IMAGE});
            $data[PromoOverlay::IMAGE] = null;
        }

        // `ttl_hours` فقط وقتی معنا دارد که frequency = ttl باشد؛ در غیر این
        // صورت پاکش می‌کنیم تا مقدارِ گمراه‌کننده در دیتابیس نماند.
        if (($data[PromoOverlay::FREQUENCY] ?? $existing?->{PromoOverlay::FREQUENCY}) !== PromoOverlay::FREQ_TTL) {
            $data[PromoOverlay::FREQUENCY_TTL_HOURS] = null;
        }

        // ستون‌هایی که NOT NULL هستند و default دارند: اگر ادمین فیلد را خالی
        // بگذارد، `ConvertEmptyStringsToNull` (میدل‌ورِ سراسریِ لاراول) رشتهٔ ""
        // را به null تبدیل می‌کند، قانونِ `nullable` هم اجازه‌اش می‌دهد، و null
        // به ستونِ NOT NULL می‌رسد → خطای SQL و پاسخِ ۵۰۰. یعنی «ذخیره نمی‌شود»
        // بدون هیچ پیامِ قابل‌فهمی.
        //
        // درست این است که کلید اصلاً فرستاده نشود: در ایجاد، defaultِ دیتابیس
        // اعمال می‌شود و در ویرایش، مقدارِ قبلی دست‌نخورده می‌ماند.
        foreach ([PromoOverlay::DELAY_SECONDS, PromoOverlay::PRIORITY, PromoOverlay::FREQUENCY, PromoOverlay::IS_ACTIVE, PromoOverlay::TITLE] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                unset($data[$col]);
            }
        }

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBadgeRequest;
use App\Http\Requests\Admin\UpdateBadgeRequest;
use App\Http\Resources\Profile\BadgeResource;
use App\Models\Badge;
use App\Models\PriceAdjustment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * پنل مدیریت — CRUD نشان‌ها (Badges).
 *
 * نشان‌ها هم در تسویه کمیسیون و هم در نمایش پروفایل استفاده می‌شوند:
 *  - کدهای gold/silver/bronze نرخ کمیسیون الیت یار را تعیین می‌کنند
 *    (SettlementCalculatorService::getCommissionRates).
 *  - pioneer/saze90 صرفاً نمایشی‌اند.
 *
 * آیکون روی دیسک public ذخیره و توسط BadgeResource به صورت URL کامل سرو می‌شود.
 */
class AdminBadgeController extends Controller
{
    // GET /admin/badges
    public function index(): JsonResponse
    {
        $badges = Badge::orderBy(Badge::ID)->paginate($this->perPage(50));

        return success(BadgeResource::collection($badges), __('admin.badges.list_received'));
    }

    // GET /admin/badges/{badge}
    public function show(Badge $badge): JsonResponse
    {
        return success(new BadgeResource($badge), __('admin.badges.received'));
    }

    // POST /admin/badges
    public function store(StoreBadgeRequest $request): JsonResponse
    {
        $data = $request->safe()->except(Badge::ICON);

        if ($request->hasFile(Badge::ICON)) {
            $data[Badge::ICON] = $request->file(Badge::ICON)->store('badges', 'public');
        }

        $badge = Badge::create($data);

        return created(new BadgeResource($badge), __('admin.badges.created'));
    }

    // PUT|POST /admin/badges/{badge}
    public function update(UpdateBadgeRequest $request, Badge $badge): JsonResponse
    {
        $data = $request->safe()->except(Badge::ICON);

        if ($request->hasFile(Badge::ICON)) {
            $this->deleteIconIfOwned($badge);
            $data[Badge::ICON] = $request->file(Badge::ICON)->store('badges', 'public');
        }

        $badge->update($data);

        return success(new BadgeResource($badge->refresh()), __('admin.badges.updated'));
    }

    // DELETE /admin/badges/{badge}
    public function destroy(Badge $badge): JsonResponse
    {
        // The canonical codes (gold/silver/bronze/saze90/pioneer) are a contract with the
        // saze90 sync (SyncUserBadgeAction looks badges up BY CODE) and drive commission
        // tiers. Never delete one that live data depends on — editing name/icon is the
        // intended admin action, not removal.
        $inUse = User::where(User::BADGE_ID, $badge->id)->exists()
            || PriceAdjustment::where(PriceAdjustment::BADGE_ID, $badge->id)->exists()
            || DB::table('badge_user')->where('badge_id', $badge->id)->exists();

        if ($inUse) {
            return error(__('admin.badges.in_use'), [], 422);
        }

        $this->deleteIconIfOwned($badge);
        $badge->delete();

        return success(null, __('admin.badges.deleted'));
    }

    /**
     * فایل آیکون قبلی را فقط وقتی پاک می‌کند که یک فایل آپلودی روی دیسک public باشد —
     * آیکون‌های با آدرس خارجی (http) را دست نمی‌زند.
     */
    private function deleteIconIfOwned(Badge $badge): void
    {
        $icon = $badge->{Badge::ICON};

        if ($icon && ! str_starts_with($icon, 'http') && Storage::disk('public')->exists($icon)) {
            Storage::disk('public')->delete($icon);
        }
    }
}

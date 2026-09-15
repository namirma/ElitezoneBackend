<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreFundedCouponRequest;
use App\Http\Resources\Marketing\CouponFunderResource;
use App\Http\Resources\Marketing\CouponFundingLegResource;
use App\Http\Resources\Marketing\CouponResource;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Services\Marketing\CouponFunderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * سمتِ تأمین‌کننده (پنلش) و الیت‌یار (پروفایلِ سایت) در تأمینِ تخفیف —
 * docs/specs/COUPON_FUNDING_2026-09-15.md §۴.
 *
 * یک کنترلر برای هر دو نقش؛ نوعِ سازنده از پیش‌فرضِ روت (`funder_type`) می‌آید.
 * درخواست‌ها و لِگ‌ها به نقش ربطی ندارند — کاربر هر چه هست، ردیفِ خودش را می‌بیند.
 */
class CouponFundingController extends Controller
{
    private const WITH = ['creator.profile', 'funders.funder.profile'];

    public function __construct(private readonly CouponFunderService $funders) {}

    // GET /profile/supplier/coupons · GET /profile/elite-yar/coupons
    // کوپن‌هایی که من ساخته‌ام یا در تأمینشان سهم دارم (هر وضعیتی).
    public function index(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();

        $query = Coupon::query()
            ->with(self::WITH)
            ->where(function ($q) use ($userId) {
                $q->where(Coupon::CREATED_BY_USER_ID, $userId)
                    ->orWhereHas('funders', fn ($f) => $f->where(CouponFunder::FUNDER_USER_ID, $userId));
            })
            ->orderByDesc(Coupon::ID);

        if ($request->filled('search')) {
            $query->where(Coupon::CODE, 'like', '%'.$request->string('search').'%');
        }

        return success(CouponResource::collection($query->paginate($this->perPage())), __('coupons.list_received'));
    }

    // POST /profile/supplier/coupons · POST /profile/elite-yar/coupons
    public function store(StoreFundedCouponRequest $request): JsonResponse
    {
        $user = $request->user();

        $coupon = DB::transaction(function () use ($request, $user) {
            $coupon = Coupon::create($request->couponAttributes() + [
                Coupon::IS_ACTIVE => true,
                Coupon::CREATED_BY_USER_ID => $user->id,
            ]);

            $this->funders->sync($coupon, $request->normalizedFunders(), $user, actorIsAdmin: false);

            return $coupon;
        });

        $coupon->load(self::WITH);

        return created(
            new CouponResource($coupon),
            __($coupon->isFundingReady() ? 'coupons.created_ready' : 'coupons.created')
        );
    }

    // POST /profile/supplier/coupons/{coupon}/toggle · POST /profile/elite-yar/coupons/{coupon}/toggle
    public function toggle(Coupon $coupon): JsonResponse
    {
        if ((int) $coupon->{Coupon::CREATED_BY_USER_ID} !== (int) Auth::id()) {
            return error(__('coupons.not_creator'), [], 403);
        }

        if ($coupon->{Coupon::USED_AT} !== null) {
            return error(__('coupons.used_locked'), [], 422);
        }

        $coupon->update([Coupon::IS_ACTIVE => ! $coupon->{Coupon::IS_ACTIVE}]);

        return success(new CouponResource($coupon->refresh()->load(self::WITH)), __('coupons.toggled'));
    }

    // GET /profile/coupon-funders?status=pending — درخواست‌های تأمین به من.
    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(CouponFunder::STATUSES)],
        ]);

        $query = CouponFunder::query()
            ->with(['funder.profile', 'coupon.creator.profile', 'coupon.funders.funder.profile'])
            ->where(CouponFunder::FUNDER_USER_ID, Auth::id())
            ->orderByDesc(CouponFunder::ID);

        if (! empty($data['status'])) {
            $query->where(CouponFunder::STATUS, $data['status']);
        }

        return success(
            CouponFunderResource::collection($query->paginate($this->perPage())),
            __('coupons.funder_requests_received')
        );
    }

    // POST /profile/coupon-funders/{funder}/accept
    public function accept(CouponFunder $funder): JsonResponse
    {
        return $this->respond($funder, true);
    }

    // POST /profile/coupon-funders/{funder}/decline
    public function decline(CouponFunder $funder): JsonResponse
    {
        return $this->respond($funder, false);
    }

    // GET /profile/coupon-funding-legs — «تخفیفِ تأمین‌شده در سفارشِ …» از کیف پولِ من.
    public function legs(): JsonResponse
    {
        $query = CouponFundingLeg::query()
            ->with(['order', 'coupon'])
            ->where(CouponFundingLeg::FUNDER_USER_ID, Auth::id())
            ->orderByDesc(CouponFundingLeg::ID);

        return success(
            CouponFundingLegResource::collection($query->paginate($this->perPage())),
            __('coupons.funding_legs_received')
        );
    }

    private function respond(CouponFunder $funder, bool $accept): JsonResponse
    {
        // فقط صاحبِ ردیف. ردیفِ شرکت `funder_user_id` ندارد، پس این‌جا هم رد می‌شود.
        if ((int) ($funder->{CouponFunder::FUNDER_USER_ID} ?? 0) !== (int) Auth::id()) {
            return error(__('coupons.funder_not_yours'), [], 403);
        }

        $this->funders->respond($funder, $accept);

        return success(
            new CouponFunderResource($funder->fresh(['funder.profile', 'coupon.creator.profile', 'coupon.funders.funder.profile'])),
            __($accept ? 'coupons.funder_accepted' : 'coupons.funder_declined')
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Http\Resources\Marketing\CouponResource;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Services\Marketing\CouponFunderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * پنل مدیریت — CRUD کدهای تخفیف (ماژول ۸ نیازمندی فرانت BackOffice).
 *
 * کوپن استفاده‌شده (used_at پر) قابل حذف نیست — 422 (سابقه مالی سفارش به آن وابسته است).
 *
 * تأمینِ تخفیف (docs/specs/COUPON_FUNDING_2026-09-15.md): `funders[]` در ساخت و
 * ویرایش؛ ردیفِ شرکت با ادمین خودکار پذیرفته می‌شود، بقیه در انتظارِ صاحبشان.
 */
class AdminCouponController extends Controller
{
    /** روابطی که پنل برای ستونِ «تأمین» و نامِ سازنده لازم دارد. */
    private const WITH = ['owner', 'creator.profile', 'funders.funder.profile'];

    public function __construct(private readonly CouponFunderService $funders) {}

    // GET /admin/coupons
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query()->with(self::WITH)->orderByDesc(Coupon::ID);

        if ($request->has('is_active')) {
            $query->where(Coupon::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where(Coupon::CODE, 'like', '%'.$request->string('search').'%');
        }

        return success(CouponResource::collection($query->paginate($this->perPage())), __('admin.coupons.list_received'));
    }

    // GET /admin/coupons/{coupon}
    public function show(Coupon $coupon): JsonResponse
    {
        return success(new CouponResource($coupon->load(self::WITH)), __('admin.coupons.received'));
    }

    // POST /admin/coupons
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $admin = auth()->user();

        $coupon = DB::transaction(function () use ($request, $admin) {
            $coupon = Coupon::create($request->couponAttributes() + [Coupon::CREATED_BY_USER_ID => $admin->id]);

            // بدونِ `funders` = شرکت ۱۰۰٪، صریح در جدول (نه فقط در محاسبه).
            $rows = $request->hasFunders()
                ? $request->normalizedFunders()
                : [['funder_type' => CouponFunder::TYPE_COMPANY, 'funder_user_id' => null, 'share_percent' => 100.0]];

            $this->funders->sync($coupon, $rows, $admin, actorIsAdmin: true);

            return $coupon;
        });

        activity()->performedOn($coupon)->causedBy($admin)
            ->withProperties(['code' => $coupon->code, 'type' => $coupon->type, 'amount' => $coupon->amount])
            ->log('کد تخفیف ساخته شد.');

        return created(new CouponResource($coupon->load(self::WITH)), __('admin.coupons.created'));
    }

    // PUT /admin/coupons/{coupon}
    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        // کد تخفیف استفاده‌شده دیگر قابل ویرایش نیست — سند مالی سفارش به آن ارجاع دارد.
        if (! is_null($coupon->{Coupon::USED_AT})) {
            return error(__('admin.coupons.used_not_editable'), [], 422);
        }

        DB::transaction(function () use ($request, $coupon) {
            $coupon->update($request->couponAttributes());

            if ($request->hasFunders()) {
                $this->funders->sync($coupon, $request->normalizedFunders(), auth()->user(), actorIsAdmin: true);
            }
        });

        activity()->performedOn($coupon)->causedBy(auth()->user())
            ->withProperties($request->validated())
            ->log('کد تخفیف ویرایش شد.');

        return success(new CouponResource($coupon->refresh()->load(self::WITH)), __('admin.coupons.updated'));
    }

    // POST /admin/coupons/{coupon}/toggle
    public function toggle(Coupon $coupon): JsonResponse
    {
        $coupon->update([Coupon::IS_ACTIVE => ! $coupon->{Coupon::IS_ACTIVE}]);

        return success(new CouponResource($coupon->refresh()->load(self::WITH)), __('admin.coupons.toggled'));
    }

    // DELETE /admin/coupons/{coupon}
    public function destroy(Coupon $coupon): JsonResponse
    {
        if (! is_null($coupon->{Coupon::USED_AT})) {
            return error(__('admin.coupons.used_not_deletable'), [], 422);
        }

        activity()->causedBy(auth()->user())
            ->withProperties(['code' => $coupon->code])
            ->log('کد تخفیف حذف شد.');

        $coupon->delete();

        return success(null, __('admin.coupons.deleted'));
    }
}

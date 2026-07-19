<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Http\Resources\Marketing\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD کدهای تخفیف (ماژول ۸ نیازمندی فرانت BackOffice).
 *
 * کوپن استفاده‌شده (used_at پر) قابل حذف نیست — 422 (سابقه مالی سفارش به آن وابسته است).
 */
class AdminCouponController extends Controller
{
    // GET /admin/coupons
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query()->with('owner')->orderByDesc(Coupon::ID);

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
        return success(new CouponResource($coupon), __('admin.coupons.received'));
    }

    // POST /admin/coupons
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($request->validated());

        return created(new CouponResource($coupon), __('admin.coupons.created'));
    }

    // PUT /admin/coupons/{coupon}
    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        // کد تخفیف استفاده‌شده دیگر قابل ویرایش نیست — سند مالی سفارش به آن ارجاع دارد.
        if (! is_null($coupon->{Coupon::USED_AT})) {
            return error(__('admin.coupons.used_not_editable'), [], 422);
        }

        $coupon->update($request->validated());

        return success(new CouponResource($coupon->refresh()), __('admin.coupons.updated'));
    }

    // POST /admin/coupons/{coupon}/toggle
    public function toggle(Coupon $coupon): JsonResponse
    {
        $coupon->update([Coupon::IS_ACTIVE => ! $coupon->{Coupon::IS_ACTIVE}]);

        return success(new CouponResource($coupon->refresh()), __('admin.coupons.toggled'));
    }

    // DELETE /admin/coupons/{coupon}
    public function destroy(Coupon $coupon): JsonResponse
    {
        if (! is_null($coupon->{Coupon::USED_AT})) {
            return error(__('admin.coupons.used_not_deletable'), [], 422);
        }

        $coupon->delete();

        return success(null, __('admin.coupons.deleted'));
    }
}

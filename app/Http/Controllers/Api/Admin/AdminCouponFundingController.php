<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Marketing\CouponFunderResource;
use App\Http\Resources\Marketing\CouponFundingLegResource;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Services\Marketing\CouponFunderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * سمتِ شرکتِ سازه در تأمینِ تخفیف (docs/specs/COUPON_FUNDING_2026-09-15.md §۴):
 * صفِ «درخواست‌های تأمینِ تخفیف» (ردیف‌های شرکت که تأمین‌کننده/الیت‌یار پیشنهاد
 * داده‌اند) و فهرستِ «تخفیف‌های تأمین‌شده» به تفکیکِ سفارش.
 */
class AdminCouponFundingController extends Controller
{
    public function __construct(private readonly CouponFunderService $funders) {}

    // GET /admin/coupon-funders?status=pending&funder_type=company
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(CouponFunder::STATUSES)],
            'funder_type' => ['nullable', Rule::in(CouponFunder::TYPES)],
        ]);

        $query = CouponFunder::query()
            ->with(['funder.profile', 'coupon.creator.profile', 'coupon.funders.funder.profile'])
            ->where(CouponFunder::FUNDER_TYPE, $data['funder_type'] ?? CouponFunder::TYPE_COMPANY)
            ->orderByDesc(CouponFunder::ID);

        if (! empty($data['status'])) {
            $query->where(CouponFunder::STATUS, $data['status']);
        }

        return success(
            CouponFunderResource::collection($query->paginate($this->perPage())),
            __('coupons.funder_requests_received')
        );
    }

    // POST /admin/coupon-funders/{funder}/accept
    public function accept(CouponFunder $funder): JsonResponse
    {
        return $this->respond($funder, true);
    }

    // POST /admin/coupon-funders/{funder}/decline
    public function decline(CouponFunder $funder): JsonResponse
    {
        return $this->respond($funder, false);
    }

    // GET /admin/coupon-funding-legs?funder_type=&coupon_id=
    public function legs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'funder_type' => ['nullable', Rule::in(CouponFunder::TYPES)],
            'coupon_id' => ['nullable', 'integer'],
        ]);

        $query = CouponFundingLeg::query()
            ->with(['order', 'coupon', 'funder.profile'])
            ->orderByDesc(CouponFundingLeg::ID);

        if (! empty($data['funder_type'])) {
            $query->where(CouponFundingLeg::FUNDER_TYPE, $data['funder_type']);
        }

        if (! empty($data['coupon_id'])) {
            $query->where(CouponFundingLeg::COUPON_ID, (int) $data['coupon_id']);
        }

        return success(
            CouponFundingLegResource::collection($query->paginate($this->perPage())),
            __('coupons.funding_legs_received')
        );
    }

    private function respond(CouponFunder $funder, bool $accept): JsonResponse
    {
        // ادمین فقط به‌جای شرکت پاسخ می‌دهد؛ ردیفِ تأمین‌کننده/الیت‌یار مالِ خودشان است.
        if (! $funder->isCompany()) {
            return error(__('coupons.funder_not_company'), [], 422);
        }

        $this->funders->respond($funder, $accept);

        activity()->performedOn($funder->coupon)->causedBy(auth()->user())
            ->withProperties(['funder_id' => $funder->id, 'accepted' => $accept])
            ->log($accept ? 'تأمین تخفیف توسط شرکت پذیرفته شد.' : 'تأمین تخفیف توسط شرکت رد شد.');

        return success(
            new CouponFunderResource($funder->fresh(['funder.profile', 'coupon.creator.profile', 'coupon.funders.funder.profile'])),
            __($accept ? 'coupons.funder_accepted' : 'coupons.funder_declined')
        );
    }
}

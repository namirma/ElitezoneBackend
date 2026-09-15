<?php

namespace App\Http\Resources\Marketing;

use App\Http\Resources\BaseJsonResource;
use App\Models\Coupon;
use App\Models\CouponFunder;
use Illuminate\Http\Request;

/**
 * یک ردیفِ تأمینِ تخفیف — در پنلِ هر سه بازیگر (docs/specs/COUPON_FUNDING_2026-09-15.md).
 *
 * @mixin CouponFunder
 */
class CouponFunderResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            CouponFunder::ID => $this->id,
            CouponFunder::COUPON_ID => $this->coupon_id,
            CouponFunder::FUNDER_TYPE => $this->funder_type,
            'funder_type_label' => CouponFunder::typeLabel((string) $this->funder_type),
            CouponFunder::FUNDER_USER_ID => $this->funder_user_id,
            'funder_name' => $this->displayName(),
            'funder_mobile' => $this->whenLoaded('funder', fn () => $this->funder?->mobile),
            CouponFunder::SHARE_PERCENT => (float) $this->share_percent,
            CouponFunder::STATUS => $this->status,
            'status_info' => [
                'key' => $this->status,
                'label' => trans('coupons.funder_statuses')[$this->status] ?? $this->status,
                'tone' => match ($this->status) {
                    CouponFunder::STATUS_ACCEPTED => 'success',
                    CouponFunder::STATUS_DECLINED => 'failure',
                    default => 'ongoing',
                },
            ],
            CouponFunder::RESPONDED_AT => $this->responded_at,
            'created_at_p' => $this->created_at_p,
            // در صفِ درخواست‌ها کوپن همراهِ ردیف می‌آید تا پنل بداند «چه کوپنی، از چه کسی».
            'coupon' => $this->whenLoaded('coupon', fn () => [
                Coupon::ID => $this->coupon->id,
                Coupon::CODE => $this->coupon->code,
                Coupon::TYPE => $this->coupon->type,
                Coupon::AMOUNT => (float) $this->coupon->amount,
                Coupon::EXPIRES_AT => $this->coupon->expires_at,
                Coupon::IS_ACTIVE => $this->coupon->is_active,
                'creator_name' => $this->coupon->relationLoaded('creator')
                    ? ($this->coupon->creator?->profile ? $this->coupon->creator->full_name : $this->coupon->creator?->mobile)
                    : null,
                'funders' => $this->coupon->relationLoaded('funders')
                    ? CouponFunderResource::collection($this->coupon->funders)
                    : null,
            ]),
        ];
    }
}

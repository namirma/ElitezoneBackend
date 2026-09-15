<?php

namespace App\Http\Resources\Marketing;

use App\Http\Resources\BaseJsonResource;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use Illuminate\Http\Request;

/**
 * «تخفیفِ تأمین‌شده در سفارشِ …» — یک لِگِ واقعاً کم‌شده از کیف پولِ یک تأمین‌کننده.
 *
 * @mixin CouponFundingLeg
 */
class CouponFundingLegResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            CouponFundingLeg::ID => $this->id,
            CouponFundingLeg::ORDER_ID => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->order_number),
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon->code),
            CouponFundingLeg::FUNDER_TYPE => $this->funder_type,
            'funder_type_label' => CouponFunder::typeLabel((string) $this->funder_type),
            CouponFundingLeg::FUNDER_USER_ID => $this->funder_user_id,
            'funder_name' => $this->funder_type === CouponFunder::TYPE_COMPANY
                ? CouponFunder::typeLabel(CouponFunder::TYPE_COMPANY)
                : $this->whenLoaded('funder', fn () => $this->funder?->profile ? $this->funder->full_name : $this->funder?->mobile),
            CouponFundingLeg::SHARE_PERCENT => (float) $this->share_percent,
            // رشته، مثلِ بقیه‌ی مبالغِ مالی — فرانت با آن جمع نمی‌زند.
            CouponFundingLeg::AMOUNT => (string) $this->amount,
            CouponFundingLeg::REVERSED_AT => $this->reversed_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}

<?php

namespace App\Http\Resources\Marketing;

use App\Http\Resources\BaseJsonResource;
use App\Models\Coupon;
use Illuminate\Http\Request;

/**
 * @mixin Coupon
 */
class CouponResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            Coupon::ID => $this->id,
            Coupon::CODE => $this->code,
            Coupon::TYPE => $this->type,
            Coupon::AMOUNT => $this->amount,
            Coupon::USER_ID => $this->user_id,
            Coupon::EXPIRES_AT => $this->expires_at,
            Coupon::USED_AT => $this->used_at,
            Coupon::REASON => $this->reason,
            Coupon::IS_ACTIVE => $this->is_active,
            'is_valid' => $this->isValid(),
            Coupon::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}

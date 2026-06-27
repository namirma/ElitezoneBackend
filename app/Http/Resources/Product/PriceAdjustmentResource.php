<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\PriceAdjustment;
use Illuminate\Http\Request;

/**
 * @mixin PriceAdjustment
 */
class PriceAdjustmentResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceAdjustment::ID => $this->id,
            PriceAdjustment::CUSTOMER_TYPE_ID => $this->customer_type_id,
            PriceAdjustment::BADGE_ID => $this->badge_id,
            PriceAdjustment::ADJUSTMENT_AMOUNT => (string) $this->adjustment_amount,
            PriceAdjustment::IS_ACTIVE => (bool) $this->is_active,
            'customer_type' => $this->whenLoaded('customerType', fn () => [
                'id' => $this->customerType->id,
                'name' => $this->customerType->name,
            ]),
            'badge' => $this->whenLoaded('badge', fn () => $this->badge ? [
                'id' => $this->badge->id,
                'name' => $this->badge->name,
                'code' => $this->badge->code,
            ] : null),
        ];
    }
}

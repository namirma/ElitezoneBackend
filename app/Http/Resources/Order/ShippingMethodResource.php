<?php

namespace App\Http\Resources\Order;

use App\Models\ShippingMethod;
use App\Http\Resources\BaseJsonResource;

/**
 * @mixin \App\Models\ShippingMethod
 */
class ShippingMethodResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            ShippingMethod::ID => $this->id,
            ShippingMethod::NAME => $this->name,
            ShippingMethod::CODE => $this->code,
            ShippingMethod::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ShippingMethod::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}

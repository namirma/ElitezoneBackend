<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\ShippingMethod;

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
            ShippingMethod::DELIVERY_NOTICE => $this->delivery_notice,
            ShippingMethod::DELIVERY_DAYS => $this->delivery_days,
            ShippingMethod::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ShippingMethod::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ShippingMethod;

class ShippingMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            ShippingMethod::ID         => $this->id,
            ShippingMethod::NAME       => $this->name,
            ShippingMethod::CODE       => $this->code,
            ShippingMethod::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ShippingMethod::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}

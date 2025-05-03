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
            ShippingMethod::CREATED_AT => $this->created_at,
            ShippingMethod::UPDATED_AT => $this->updated_at,
        ];
    }
}

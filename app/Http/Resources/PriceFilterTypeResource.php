<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\PriceFilterType;

class PriceFilterTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceFilterType::ID         => $this->id,
            PriceFilterType::NAME       => $this->name,
            PriceFilterType::CODE       => $this->code,
            PriceFilterType::APPLY_ON   => $this->apply_on,
            PriceFilterType::IS_ACTIVE  => (bool) $this->is_active,
            PriceFilterType::SORT_ORDER => $this->sort_order,
            PriceFilterType::CREATED_AT => $this->created_at,
            PriceFilterType::UPDATED_AT => $this->updated_at,
        ];
    }
}

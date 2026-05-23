<?php

namespace App\Http\Resources\Product;

use App\Models\PriceFilterType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceFilterTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceFilterType::ID => (string) $this->id,
            PriceFilterType::NAME => $this->name,
            PriceFilterType::CODE => $this->code,
            PriceFilterType::APPLY_ON => $this->apply_on,
            PriceFilterType::SORT_ORDER => (string) $this->sort_order,
            PriceFilterType::CREATED_AT => optional($this->created_at)
                ->format('Y-m-d H:i:s'),
            PriceFilterType::UPDATED_AT => optional($this->updated_at)
                ->format('Y-m-d H:i:s'),
        ];
    }
}

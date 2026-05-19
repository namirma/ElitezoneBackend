<?php

namespace App\Http\Resources;

use App\Models\PriceFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceFilterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceFilter::ID => $this->id,
            PriceFilter::PRICE_FILTER_TYPE_ID => $this->price_filter_type_id,
            PriceFilter::NAME => $this->name,
            PriceFilter::CODE => $this->code,
            PriceFilter::AMOUNT => $this->amount,
            PriceFilter::IS_PERCENTAGE => (bool) $this->is_percentage,
            PriceFilter::SORT_ORDER => $this->sort_order,
            PriceFilter::CREATED_AT => $this->created_at,
            PriceFilter::UPDATED_AT => $this->updated_at,
        ];
    }
}

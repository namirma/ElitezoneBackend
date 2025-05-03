<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\PriceFilterCategory;

class PriceFilterCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceFilterCategory::ID           => $this->id,
            PriceFilterCategory::NAME         => $this->name,
            PriceFilterCategory::CODE         => $this->code,
            PriceFilterCategory::DESCRIPTION  => $this->description,
            PriceFilterCategory::IS_ACTIVE    => (bool) $this->is_active,
            PriceFilterCategory::SORT_ORDER   => $this->sort_order,
            PriceFilterCategory::CREATED_AT   => $this->created_at->toISOString(),
            PriceFilterCategory::UPDATED_AT   => $this->updated_at->toISOString(),

            'filters' => $this->filters->map(function($filter) {
                return [
                    'id'                        => $filter->id,
                    'price_filter_category_id'  => $filter->pivot->price_filter_category_id,
                    'name'                      => $filter->name,
                    'code'                      => $filter->code,
                    'amount'                    => $filter->amount,
                    'is_percentage'             => (bool)$filter->is_percentage,
                    'is_active'                 => (bool)$filter->is_active,
                    'sort_order'                => $filter->sort_order,
                    'created_at'                => $filter->created_at->toISOString(),
                    'updated_at'                => $filter->updated_at->toISOString(),
                    'pivot' => [
                        'price_filter_category_id' => $filter->pivot->price_filter_category_id,
                        'price_filter_id'          => $filter->pivot->price_filter_id,
                        'created_at'               => $filter->pivot->created_at->toISOString(),
                        'updated_at'               => $filter->pivot->updated_at->toISOString(),
                    ],
                    'filter_type' => [
                        'id'         => $filter->filterType->id,
                        'name'       => $filter->filterType->name,
                        'code'       => $filter->filterType->code,
                        'apply_on'   => $filter->filterType->apply_on,
                        'is_active'  => (bool)$filter->filterType->is_active,
                        'sort_order' => $filter->filterType->sort_order,
                        'created_at' => $filter->filterType->created_at->toISOString(),
                        'updated_at' => $filter->filterType->updated_at->toISOString(),
                    ],
                ];
            }),
        ];
    }
}

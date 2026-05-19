<?php

namespace App\Http\Resources;

use App\Models\PriceFilterCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceFilterCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PriceFilterCategory::ID => $this->id,
            PriceFilterCategory::NAME => $this->name,
            PriceFilterCategory::CODE => $this->code,
            PriceFilterCategory::DESCRIPTION => $this->description,
            PriceFilterCategory::SORT_ORDER => $this->sort_order,
            PriceFilterCategory::CREATED_AT => $this->created_at->format('Y-m-d H:i:s'),
            PriceFilterCategory::UPDATED_AT => $this->updated_at->format('Y-m-d H:i:s'),

            'filters' => $this->filters->map(function ($filter) {
                return [
                    'id' => $filter->id,
                    'name' => $filter->name,
                    'code' => $filter->code,
                    'amount' => $filter->amount,
                    'is_percentage' => (bool) $filter->is_percentage,
                    'is_active' => (bool) $filter->is_active,
                    'sort_order' => $filter->sort_order,
                    'created_at' => $filter->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $filter->updated_at->format('Y-m-d H:i:s'),
                    'filter_type' => [
                        'id' => $filter->filterType->id,
                        'name' => $filter->filterType->name,
                        'code' => $filter->filterType->code,
                        'apply_on' => $filter->filterType->apply_on,
                    ],
                ];
            }),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\PriceFilterCategoryResource;
use App\Http\Resources\PriceFilterTypeResource;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use Illuminate\Http\JsonResponse;

class PriceFilterController extends Controller
{
    public function getCategories(): JsonResponse
    {
        $categories = PriceFilterCategory::active()
            ->with(['filters' => function ($q) {
                $q->active()->with('filterType');
            }])
            ->orderBy('sort_order')
            ->get();

        return success(
            PriceFilterCategoryResource::collection($categories)
        );
    }

    public function getFilterTypes(): JsonResponse
    {
        $types = PriceFilterType::active()
            ->with(['filters' => fn ($q) => $q->active()])
            ->orderBy('sort_order')
            ->get();

        return success(
            PriceFilterTypeResource::collection($types)
        );
    }
}

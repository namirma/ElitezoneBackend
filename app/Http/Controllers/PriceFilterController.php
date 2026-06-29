<?php

namespace App\Http\Controllers;

use App\Http\Resources\Product\PriceFilterCategoryResource;
use App\Http\Resources\Product\PriceFilterTypeResource;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PriceFilterController extends Controller
{
    /** داده‌ها هفته‌ای تغییر می‌کنند — 1 ساعت TTL کافی است */
    private const CACHE_TTL = 3600;

    public function getCategories(): JsonResponse
    {
        $categories = Cache::remember('price_filter_categories', self::CACHE_TTL, function () {
            return PriceFilterCategory::active()
                ->with(['filters' => function ($q) {
                    $q->active()->with('filterType');
                }])
                ->orderBy('sort_order')
                ->get();
        });

        return success(
            PriceFilterCategoryResource::collection($categories)
        );
    }

    public function getFilterTypes(): JsonResponse
    {
        $types = Cache::remember('price_filter_types', self::CACHE_TTL, function () {
            return PriceFilterType::active()
                ->with(['filters' => fn ($q) => $q->active()])
                ->orderBy('sort_order')
                ->get();
        });

        return success(
            PriceFilterTypeResource::collection($types)
        );
    }
}

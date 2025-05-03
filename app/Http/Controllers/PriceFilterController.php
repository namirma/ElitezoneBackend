<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use Illuminate\Http\Request;

class PriceFilterController extends Controller
{

    public function getCategories()
    {
        $categories = PriceFilterCategory::active()
            ->with(['filters' => function($query) {
                $query->active()->with('filterType');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }

    
    public function getFilterTypes()
    {
        $filterTypes = PriceFilterType::active()
            ->with(['filters' => function($query) {
                $query->active();
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $filterTypes
        ]);
    }
}

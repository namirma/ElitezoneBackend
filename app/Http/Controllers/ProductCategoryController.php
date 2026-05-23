<?php

namespace App\Http\Controllers;

use App\Http\Resources\Product\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::query()
            ->active()
            ->orderBy(ProductCategory::ID, 'asc')
            ->get();

        return success(
            ProductCategoryResource::collection($categories),
            'global.received_successfully'
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Data;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\InventoryPredictionResource;
use App\Models\InventoryPrediction;

class InventoryController extends Controller
{
    /**
     * گزارش کالاهای نیازمند شارژ (Restock Report)
     */
    public function predictions()
    {
        // ۱. دریافت داده‌ها از دیتابیس
        $data = InventoryPrediction::with('variant.product')
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'normal', 'low')")
            ->orderByDesc('predicted_demand_next_7d')
            ->get();

        return InventoryPredictionResource::collection($data);
    }
}

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
        // چون تاریخچه‌ی اسنپ‌شات‌ها برای backtesting نگه داشته می‌شود، فقط آخرین
        // اجرا (بیشینه‌ی calculated_at) به‌عنوان وضعیت جاری برگردانده می‌شود.
        $latestRun = InventoryPrediction::max(InventoryPrediction::CALCULATED_AT);

        $data = InventoryPrediction::with('variant.product')
            ->when($latestRun, fn ($q) => $q->where(InventoryPrediction::CALCULATED_AT, $latestRun))
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'normal', 'low')")
            ->orderByDesc('predicted_demand_next_7d')
            ->get();

        return InventoryPredictionResource::collection($data);
    }
}

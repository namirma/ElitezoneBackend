<?php

namespace App\Http\Controllers\Api\Data;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\InventoryPredictionResource;
use App\Models\InventoryPrediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class InventoryController extends Controller
{
    /**
     * گزارش کالاهای نیازمند شارژ (Restock Report)
     */
    public function predictions(): JsonResponse
    {
        // چون تاریخچه‌ی اسنپ‌شات‌ها برای backtesting نگه داشته می‌شود، فقط آخرین
        // اجرا (بیشینه‌ی calculated_at) به‌عنوان وضعیت جاری برگردانده می‌شود.
        // max() به ازای هر درخواست یک query می‌زند — کش 5 دقیقه‌ای اثر را کاهش می‌دهد.
        $latestRun = Cache::remember('inventory_predictions_latest_run', 300, fn () => InventoryPrediction::max(InventoryPrediction::CALCULATED_AT)
        );

        $data = InventoryPrediction::with('variant.product')
            ->when($latestRun, fn ($q) => $q->where(InventoryPrediction::CALCULATED_AT, $latestRun))
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'normal', 'low')")
            ->orderByDesc('predicted_demand_next_7d')
            ->paginate($this->perPage(50));

        return success(InventoryPredictionResource::collection($data)->response()->getData(true), __('admin.inventory_predictions_received'));
    }
}

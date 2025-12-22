<?php

namespace App\Http\Controllers\Api\Data;

use App\Http\Controllers\Controller;
use App\Models\InventoryPrediction;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    /**
     * گزارش کالاهای نیازمند شارژ (Restock Report)
     */
    public function predictions(): JsonResponse
    {
        $report = InventoryPrediction::with('variant.product')
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'normal', 'low')")
            ->orderByDesc('predicted_demand_next_7d')
            ->get()
            ->map(function ($item) {
                return [
                    'product' => $item->variant->product->name . ' - ' . $item->variant->name,
                    'current_stock' => $item->current_stock,
                    'daily_sales' => $item->sales_velocity_30d, // سرعت فروش
                    'search_interest' => $item->search_volume_7d, // میزان جستجو
                    'predicted_demand' => $item->predicted_demand_next_7d, // پیش‌بینی هفته بعد
                    'restock_needed' => $item->recommended_restock_amount, // پیشنهاد خرید
                    'priority' => $item->priority,
                    'status_message' => $item->reason,
                ];
            });

        return response()->json($report);
    }
}

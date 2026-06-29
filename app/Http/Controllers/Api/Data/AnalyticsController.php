<?php

namespace App\Http\Controllers\Api\Data;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * دریافت تمام داده‌های داشبورد یکجا
     */
    public function dashboard(): JsonResponse
    {
        return success([
            'revenue_chart'  => $this->analyticsService->getRevenueChartData(30),
            'sales_funnel'   => $this->analyticsService->getSalesFunnel(),
            'rfm_segments'   => $this->analyticsService->getCustomerSegmentsChart(),
            'customer_value' => $this->analyticsService->getCustomerValueMetrics(),
            'top_products'   => $this->analyticsService->getTopProducts(),
        ], __('admin.analytics_received'));
    }

    /**
     * دریافت اختصاصی نمودار فروش (مثلاً برای فیلتر بازه زمانی)
     */
    public function revenue(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 30);

        return success(
            $this->analyticsService->getRevenueChartData($days),
            __('admin.revenue_received')
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * پنل مدیریت — داشبورد اجرایی (KPI)
 *
 * کنترلر فقط orchestrate می‌کند — تمام query logic در AdminDashboardService است.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardService $dashboard,
    ) {}

    public function index(): JsonResponse
    {
        $today      = now()->startOfDay();
        $weekStart  = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        return success([
            'today'            => $this->dashboard->todayStats($today),
            'pipeline'         => $this->dashboard->orderPipeline(),
            'financial_alerts' => $this->dashboard->financialAlerts(),
            'weekly_trend'     => $this->dashboard->weeklyOrderTrend($weekStart),
            'month_summary'    => $this->dashboard->monthlySummary($monthStart),
        ], __('admin.dashboard.received'));
    }
}

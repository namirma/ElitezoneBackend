<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * پنل مدیریت — داشبورد اجرایی (KPI)
 *
 * تمام اعداد به ریال هستند.
 */
class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today      = now()->startOfDay();
        $weekStart  = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        return success([
            'today'           => $this->todayStats($today),
            'pipeline'        => $this->orderPipeline(),
            'financial_alerts' => $this->financialAlerts(),
            'weekly_trend'    => $this->weeklyOrderTrend($weekStart),
            'month_summary'   => $this->monthlySummary($monthStart),
        ], __('admin.dashboard.received'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * آمار همین امروز: کاربر جدید، سفارش جدید، درآمد پرداخت‌شده
     */
    private function todayStats(Carbon $today): array
    {
        return [
            'new_users'   => User::whereDate('created_at', $today)->count(),
            'new_orders'  => Order::whereDate('created_at', $today)->count(),
            'revenue'     => (float) Order::whereDate('created_at', $today)
                ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID)
                ->sum(Order::FINAL_AMOUNT),
        ];
    }

    /**
     * تعداد و مجموع مبلغ سفارشات به تفکیک هر وضعیت (pipeline)
     */
    private function orderPipeline(): array
    {
        $rows = Order::query()
            ->select(
                Order::STATUS,
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(final_amount) as total_amount')
            )
            ->groupBy(Order::STATUS)
            ->get()
            ->keyBy(Order::STATUS);

        $statuses = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
        ];

        return collect($statuses)->map(fn ($s) => [
            'status'       => $s,
            'count'        => (int) ($rows[$s]->count ?? 0),
            'total_amount' => (float) ($rows[$s]->total_amount ?? 0),
        ])->values()->toArray();
    }

    /**
     * هشدارهای عملیاتی — نیازمند اقدام فوری ادمین
     *
     * - واریزهای معلق (تایید نشده)
     * - بدهی سوپلایر (باسکول کمتر از سفارش)
     * - فاکتور مودیان ثبت‌نشده (Doc Q/O/P)
     * - رزروهای منقضی‌شده (آماده کنسلی)
     */
    private function financialAlerts(): array
    {
        $pendingDeposits = WalletTransaction::where(WalletTransaction::TYPE, WalletTransaction::TYPE_DEPOSIT)
            ->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING)
            ->count();

        $pendingDepositAmount = (float) WalletTransaction::where(WalletTransaction::TYPE, WalletTransaction::TYPE_DEPOSIT)
            ->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING)
            ->sum(WalletTransaction::AMOUNT);

        $supplierDebtCount = Order::where(Order::SUPPLIER_DEBT_PENDING, true)->count();

        $supplierDebtAmount = (float) Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->sum(Order::SUPPLIER_DEBT_AMOUNT);

        // سفارشاتی که باسکول شده‌اند اما هنوز یک یا چند سند مودیان ثبت نشده
        $pendingMoadianCount = Order::query()
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(function ($q) {
                $q->whereNull(Order::CONSULTANT_MOADIAN_REF)
                    ->orWhereNull(Order::SUPPLIER_MOADIAN_REF)
                    ->orWhereNull(Order::SYSTEM_MOADIAN_REF);
            })
            ->whereIn(Order::STATUS, [
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_DELIVERED,
            ])
            ->count();

        $expiredReservationCount = Order::query()
            ->whereIn(Order::STATUS, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])
            ->whereNotNull(Order::RESERVATION_EXPIRES_AT)
            ->where(Order::RESERVATION_EXPIRES_AT, '<', now())
            ->count();

        $alertCount = $pendingDeposits + $supplierDebtCount + $pendingMoadianCount + $expiredReservationCount;

        return [
            'has_alerts'               => $alertCount > 0,
            'total_alerts'             => $alertCount,
            'pending_deposits'         => $pendingDeposits,
            'pending_deposits_amount'  => $pendingDepositAmount,
            'supplier_debts'           => $supplierDebtCount,
            'supplier_debts_amount'    => $supplierDebtAmount,
            'pending_moadian_invoices' => $pendingMoadianCount,
            'expired_reservations'     => $expiredReservationCount,
        ];
    }

    /**
     * روند روزانه سفارشات از ابتدای هفته جاری تا امروز
     */
    private function weeklyOrderTrend(Carbon $weekStart): array
    {
        return Order::query()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(final_amount) as revenue')
            )
            ->where('created_at', '>=', $weekStart)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get()
            ->map(fn ($row) => [
                'date'    => $row->date,
                'count'   => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ])
            ->toArray();
    }

    /**
     * خلاصه ماه جاری: درآمد پرداخت‌شده، تعداد سفارشات، تعداد کاربران
     */
    private function monthlySummary(Carbon $monthStart): array
    {
        return [
            'paid_revenue'         => (float) Order::where('created_at', '>=', $monthStart)
                ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID)
                ->sum(Order::FINAL_AMOUNT),
            'orders_count'         => Order::where('created_at', '>=', $monthStart)->count(),
            'total_users'          => User::count(),
            'new_users_this_month' => User::where('created_at', '>=', $monthStart)->count(),
        ];
    }
}

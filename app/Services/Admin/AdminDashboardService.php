<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * سرویس خواندن اطلاعات داشبورد ادمین.
 *
 * این کلاس هیچ side-effect ای ندارد — فقط query می‌زند و داده برمی‌گرداند.
 * AdminDashboardController فقط این سرویس را inject می‌کند و پاسخ می‌سازد.
 *
 * تمام مبالغ به ریال هستند.
 */
class AdminDashboardService
{
    /**
     * آمار همین امروز: کاربر جدید، سفارش جدید، درآمد پرداخت‌شده
     *
     * @return array{new_users: int, new_orders: int, revenue: float}
     */
    public function todayStats(Carbon $today): array
    {
        return [
            'new_users' => User::whereDate('created_at', $today)->count(),
            'new_orders' => Order::whereDate('created_at', $today)->count(),
            'revenue' => (float) Order::whereDate('created_at', $today)
                ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID)
                ->sum(Order::FINAL_AMOUNT),
        ];
    }

    /**
     * تعداد و مجموع مبلغ سفارشات به تفکیک هر وضعیت (pipeline کامل)
     *
     * @return list<array{status: string, count: int, total_amount: float}>
     */
    public function orderPipeline(): array
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
            'status' => $s,
            'count' => (int) ($rows[$s]->count ?? 0),
            'total_amount' => (float) ($rows[$s]->total_amount ?? 0),
        ])->values()->toArray();
    }

    /**
     * هشدارهای مالی و عملیاتی — نیازمند اقدام فوری ادمین
     *
     * - واریزهای تایید‌نشده (pending deposits)
     * - بدهی باسکول سوپلایر (supplier_debt_pending)
     * - سفارشات باسکول‌شده فاقد فاکتور مودیان (Doc Q/O/P)
     * - رزروهای منقضی‌شده آماده کنسلی
     *
     * @return array{has_alerts: bool, total_alerts: int, ...}
     */
    public function financialAlerts(): array
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

        // سفارشاتی که باسکول شده‌اند اما یک یا چند سند مودیان هنوز ثبت نشده
        $pendingMoadianCount = Order::query()
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(function ($q) {
                $q->whereNull(Order::ELITE_YAR_MOADIAN_REF)
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

        $totalAlerts = $pendingDeposits + $supplierDebtCount + $pendingMoadianCount + $expiredReservationCount;

        return [
            'has_alerts' => $totalAlerts > 0,
            'total_alerts' => $totalAlerts,
            'pending_deposits' => $pendingDeposits,
            'pending_deposits_amount' => $pendingDepositAmount,
            'supplier_debts' => $supplierDebtCount,
            'supplier_debts_amount' => $supplierDebtAmount,
            'pending_moadian_invoices' => $pendingMoadianCount,
            'expired_reservations' => $expiredReservationCount,
        ];
    }

    /**
     * روند روزانه سفارشات از ابتدای هفته جاری تا امروز
     *
     * @return list<array{date: string, count: int, revenue: float}>
     */
    public function weeklyOrderTrend(Carbon $weekStart): array
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
                'date' => $row->date,
                'count' => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ])
            ->toArray();
    }

    /**
     * خلاصه ماه جاری: درآمد پرداخت‌شده، تعداد سفارشات، تعداد کاربران
     *
     * @return array{paid_revenue: float, orders_count: int, total_users: int, new_users_this_month: int}
     */
    public function monthlySummary(Carbon $monthStart): array
    {
        return [
            'paid_revenue' => (float) Order::where('created_at', '>=', $monthStart)
                ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID)
                ->sum(Order::FINAL_AMOUNT),
            'orders_count' => Order::where('created_at', '>=', $monthStart)->count(),
            'total_users' => User::count(),
            'new_users_this_month' => User::where('created_at', '>=', $monthStart)->count(),
        ];
    }
}

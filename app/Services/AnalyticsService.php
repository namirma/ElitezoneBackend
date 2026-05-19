<?php

namespace App\Services;

use App\Models\CartEvent;
use App\Models\CustomerStat;
use App\Models\Order;
use App\Models\OrderItem; // <--- استفاده از مدل صحیح سرچ شما
use App\Models\Search;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * 1. نمودار فروش ۳۰ روز گذشته (برای رسم نمودار خطی)
     */
    public function getRevenueChartData(int $days = 30): array
    {
        // کش کردن نتیجه برای ۱ ساعت
        return Cache::remember("analytics_revenue_{$days}", 60 * 60, function () use ($days) {

            $startDate = now()->subDays($days)->startOfDay();

            // دریافت مجموع فروش روزانه
            $rawData = Order::query()
                ->where(Order::STATUS, Order::STATUS_COMPLETED) // فقط سفارش‌های موفق
                ->where(Order::CREATED_AT, '>=', $startDate)
                ->select([
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(final_amount) as total'),
                ])
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->pluck('total', 'date')
                ->toArray();

            // پر کردن روزهای خالی با صفر
            $labels = [];
            $values = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $labels[] = $date;
                $values[] = $rawData[$date] ?? 0;
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'فروش (تومان)',
                        'data' => $values,
                        'fill' => true,
                        'borderColor' => '#4F46E5',
                        'tension' => 0.4,
                    ],
                ],
            ];
        });
    }

    /**
     * 2. نمودار قیف فروش (Funnel)
     * بازدید (Search) -> علاقه (Cart) -> خرید (Order)
     */
    public function getSalesFunnel(): array
    {
        return Cache::remember('analytics_funnel', 60 * 30, function () {

            // مرحله ۱: جستجو (Interest) - استفاده از مدل Search شما
            $searchCount = Search::query()
                ->where(Search::CREATED_AT, '>=', now()->subDays(30))
                ->count();

            // مرحله ۲: سبد خرید (Intent)
            $cartCount = CartEvent::query()
                ->where('action_type', 'add')
                ->where('created_at', '>=', now()->subDays(30))
                ->distinct('user_id') // تعداد کاربران یکتا
                ->count();

            // مرحله ۳: خرید نهایی (Action)
            $orderCount = Order::query()
                ->where(Order::CREATED_AT, '>=', now()->subDays(30))
                ->distinct(Order::PLACER_USER_ID)
                ->count();

            return [
                'labels' => ['جستجو (Interest)', 'سبد خرید (Intent)', 'خرید (Action)'],
                'datasets' => [
                    [
                        'label' => 'تعداد تعامل',
                        'data' => [$searchCount, $cartCount, $orderCount],
                        'backgroundColor' => ['#60A5FA', '#F59E0B', '#10B981'],
                    ],
                ],
            ];
        });
    }

    /**
     * 3. دسته‌بندی مشتریان (RFM Segments)
     */
    public function getCustomerSegmentsChart(): array
    {
        return Cache::remember('analytics_rfm_segments', 60 * 60 * 24, function () {
            $data = CustomerStat::query()
                ->select(CustomerStat::SEGMENT, DB::raw('count(*) as count'))
                ->groupBy(CustomerStat::SEGMENT)
                ->get();

            return [
                'labels' => $data->pluck(CustomerStat::SEGMENT),
                'datasets' => [
                    [
                        'data' => $data->pluck('count'),
                        'backgroundColor' => [
                            '#10B981', // Champion
                            '#3B82F6', // Loyal
                            '#F59E0B', // Potential
                            '#EF4444', // At Risk
                            '#6B7280', // Others
                        ],
                    ],
                ],
            ];
        });
    }

    /**
     * 4. محصولات برتر (Top Products)
     */
    public function getTopProducts(): array
    {
        return Cache::remember('analytics_top_products', 60 * 60, function () {
            return OrderItem::query()
                ->select(
                    'product_variant_id',
                    DB::raw('SUM(JSON_EXTRACT(quantity_details, "$.quantity")) as total_qty'),
                    DB::raw('SUM(total_price) as total_revenue')
                )
                ->with('variant.product')
                ->groupBy('product_variant_id')
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $item->variant->product->name ?? 'Unknown',
                        'variant' => $item->variant->name ?? '-',
                        'qty' => $item->total_qty ?? 0,
                        'revenue' => $item->total_revenue,
                    ];
                })->toArray();
        });
    }
}

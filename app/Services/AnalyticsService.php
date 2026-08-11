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
            // ⚠️ فقط STATUS_COMPLETED غلط بود: ماشین وضعیت سفارش هرگز به «completed»
            // نمی‌رسد (وضعیت پایانیِ واقعی «delivered» است — AdminOrderController)، پس
            // نمودار روند فروش همیشه خالی می‌ماند. مثل CalculateCustomerRfm و
            // HasOrderProgress، هر دو وضعیتِ «سفارشِ به‌سرانجام‌رسیده» شمرده می‌شوند.
            $rawData = Order::query()
                ->where(Order::STATUS, Order::STATUS_DELIVERED)
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
     * 5. متریک‌های ارزش و ریزش مشتری (CLV / Churn).
     *
     * بر پایه‌ی CustomerStat که job RFM پر می‌کند:
     *  - CLV (تخمینی، تاریخی): میانگین monetary_total مشتریان فعال.
     *  - Churn rate: نسبت مشتریانِ «از دست رفته» (recency > آستانه‌ی churn یا سگمنت lost).
     *  - میانگین عمر سفارش‌دهی و میانگین تعداد سفارش.
     */
    public function getCustomerValueMetrics(): array
    {
        return Cache::remember('analytics_customer_value', 60 * 60, function () {
            $churnDays = (int) config('intelligence.rfm.churn_days', 180);

            $total = (int) CustomerStat::query()->count();

            if ($total === 0) {
                return [
                    'total_customers' => 0,
                    'avg_clv' => 0,
                    'avg_orders_per_customer' => 0,
                    'churned_customers' => 0,
                    'churn_rate' => 0.0,
                    'active_customers' => 0,
                ];
            }

            $avgClv = (float) CustomerStat::query()->avg(CustomerStat::MONETARY_TOTAL);
            $avgOrders = (float) CustomerStat::query()->avg(CustomerStat::FREQUENCY_COUNT);

            // ریزش: یا سگمنت lost است یا recency از آستانه گذشته.
            $churned = (int) CustomerStat::query()
                ->where(function ($q) use ($churnDays) {
                    $q->where(CustomerStat::SEGMENT, CustomerStat::SEGMENT_LOST)
                        ->orWhere(CustomerStat::RECENCY_DAYS, '>', $churnDays);
                })
                ->count();

            return [
                'total_customers' => $total,
                'avg_clv' => round($avgClv),
                'avg_orders_per_customer' => round($avgOrders, 2),
                'churned_customers' => $churned,
                'churn_rate' => round(($churned / $total) * 100, 2), // درصد
                'active_customers' => $total - $churned,
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
                    // ⚠️ قبلاً `JSON_EXTRACT(quantity_details, "$.quantity")` بود، ولی
                    // شکل واقعی این ستون نگاشتِ «شناسه واحد سفارش → تعداد» است
                    // (مثلاً `{"2":1}` یعنی ۱ بندیل) و هیچ کلیدی به نام `quantity`
                    // ندارد. پس JSON_EXTRACT همیشه NULL برمی‌گرداند و ستون «عدد» در
                    // «محصولات پرفروش» همیشه صفر نشان داده می‌شد — بی‌صدا، بدون خطا.
                    // `order_items.quantity` یک ستون واقعی است و همین عدد را دارد.
                    DB::raw('SUM(quantity) as total_qty'),
                    DB::raw('SUM(total_price) as total_revenue')
                )
                // ⚠️ رابطه‌ی OrderItem اسمش `productVariant` است، نه `variant`.
                // این باگ تا امروز خفته بود چون دیتابیس صفر سفارش داشت: لاراول
                // وقتی نتیجه‌ی کوئری خالی باشد eager-load را اجرا نمی‌کند، پس با
                // اولین سفارشِ واقعی، صفحه‌ی «گزارش‌ها و تحلیل‌ها» با
                // «Call to undefined relationship [variant]» می‌شکست.
                ->with('productVariant.product')
                ->groupBy('product_variant_id')
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $item->productVariant->product->name ?? 'Unknown',
                        'variant' => $item->productVariant->name ?? '-',
                        'qty' => $item->total_qty ?? 0,
                        'revenue' => $item->total_revenue,
                    ];
                })->toArray();
        });
    }
}

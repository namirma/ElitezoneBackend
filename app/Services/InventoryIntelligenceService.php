<?php

namespace App\Services;

use App\Models\InventoryPrediction;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Search;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryIntelligenceService
{
    /**
     * اجرای تحلیل برای تمام محصولات
     */
    public function runAnalysis(): void
    {
        // ۱. دریافت تنظیمات از فایل کانفیگ (برای جلوگیری از هاردکد کردن)
        $conf = [
            'sales_days' => config('intelligence.inventory.sales_period_days', 30),
            'search_days' => config('intelligence.inventory.search_period_days', 7),
            'prediction_days' => config('intelligence.inventory.prediction_days', 7),
            'conversion_rate' => config('intelligence.inventory.search_conversion_rate', 0.1),
            'high_search' => config('intelligence.inventory.high_search_threshold', 50),
        ];

        // ۲. پاک کردن تحلیل‌های قدیمی
        InventoryPrediction::truncate();

        // ۳. گرفتن داده‌های اولیه
        $variants = ProductVariant::with('product')->get();

        // ۴. دریافت آمار با استفاده از بازه زمانی کانفیگ شده
        $salesStats = $this->getSalesStats($conf['sales_days']);
        $searchStats = $this->getSearchStats($conf['search_days']);

        // ۵. تحلیل تک‌تک محصولات
        foreach ($variants as $variant) {
            $this->analyzeVariant($variant, $salesStats, $searchStats, $conf);
        }
    }

    private function analyzeVariant($variant, $salesStats, $searchStats, array $conf)
    {
        // الف) محاسبه سرعت فروش (Velocity)
        $totalSold = $salesStats[$variant->id] ?? 0;
        $velocity = $totalSold / $conf['sales_days']; // میانگین فروش روزانه

        // ب) محاسبه حجم جستجو (Search Interest)
        $searchVolume = $searchStats->filter(function ($count, $query) use ($variant) {
            // نرمال‌سازی ساده (حذف فاصله‌ها برای دقت بیشتر)
            return str_contains($query, $variant->name) || str_contains($query, $variant->sku);
        })->sum();

        // ج) فرمول پیش‌بینی تقاضا (AI Formula)
        // تقاضا = (سرعت فروش * روزهای آینده) + (جستجو * ضریب تبدیل)
        $potentialFromSearch = $searchVolume * $conf['conversion_rate'];
        $predictedDemand = ($velocity * $conf['prediction_days']) + $potentialFromSearch;

        // د) تحلیل شکاف موجودی (Gap Analysis)
        $currentStock = $variant->stock_quantity ?? 0;
        $restockNeeded = max(0, $predictedDemand - $currentStock);

        // ه) تعیین اولویت و دلیل (Logic)
        $priority = InventoryPrediction::PRIORITY_LOW;
        $reason = 'وضعیت نرمال';

        // سناریو ۱: بحرانی (موجودی صفر + تقاضا داریم)
        if ($currentStock == 0 && $predictedDemand > 0.5) {
            $priority = InventoryPrediction::PRIORITY_CRITICAL;
            $reason = 'توقف فروش! (موجودی صفر، تقاضای فعال)';
        }
        // سناریو ۲: هشدار (موجودی داریم ولی تا هفته بعد تمام می‌شود)
        elseif ($restockNeeded > 0) {
            $priority = InventoryPrediction::PRIORITY_HIGH;
            $reason = "خطر اتمام موجودی در {$conf['prediction_days']} روز آینده";
        }
        // سناریو ۳: تقاضای نهفته (جستجوی بالا ولی فروش پایین)
        elseif ($searchVolume > $conf['high_search'] && $velocity < 1) {
            $priority = InventoryPrediction::PRIORITY_NORMAL;
            $reason = 'تقاضای نهفته (جستجوی بالا ولی عدم ثبت سفارش)';
        }

        // و) ذخیره در دیتابیس (فقط اگر نکته‌ای وجود دارد)
        if ($predictedDemand > 0 || $currentStock < 10) {
            InventoryPrediction::create([
                InventoryPrediction::PRODUCT_VARIANT_ID => $variant->id,
                InventoryPrediction::CURRENT_STOCK => $currentStock,
                InventoryPrediction::SALES_VELOCITY_30D => number_format($velocity, 2),
                InventoryPrediction::SEARCH_VOLUME_7D => $searchVolume,
                InventoryPrediction::PREDICTED_DEMAND_NEXT_7D => number_format($predictedDemand, 2),
                InventoryPrediction::RECOMMENDED_RESTOCK_AMOUNT => ceil($restockNeeded),
                InventoryPrediction::PRIORITY => $priority,
                InventoryPrediction::REASON => $reason,
                InventoryPrediction::CALCULATED_AT => now(),
            ]);
        }
    }

    // --- Helper Methods (Optimized) ---

    private function getSalesStats(int $days): Collection
    {
        return OrderItem::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select('product_variant_id')
            ->sumQuantity('total_qty')
            ->groupBy('product_variant_id')
            ->pluck('total_qty', 'product_variant_id');
    }

    private function getSearchStats(int $days): Collection
    {
        return Search::query()
            ->where(Search::CREATED_AT, '>=', now()->subDays($days))
            ->select(Search::QUERY, DB::raw('COUNT(*) as count'))
            ->groupBy(Search::QUERY)
            ->pluck('count', Search::QUERY);
    }
}

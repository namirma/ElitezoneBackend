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
        // ۱. پاک کردن تحلیل‌های قدیمی (چون موجودی لحظه‌ای عوض میشه)
        InventoryPrediction::truncate();

        // ۲. گرفتن تمام محصولات فعال با موجودی و نام
        $variants = ProductVariant::with('product')->get();

        // ۳. گرفتن آمار فروش ۳۰ روزه برای همه محصولات (یک کوئری بهینه)
        $salesStats = $this->getSalesStats();

        // ۴. گرفتن آمار جستجو برای کلمات کلیدی (یک کوئری بهینه)
        // نکته: این بخش ساده‌سازی شده است. در سیستم واقعی باید نام محصول را نرمالایز کنیم
        $searchStats = $this->getSearchStats();

        foreach ($variants as $variant) {
            $this->analyzeVariant($variant, $salesStats, $searchStats);
        }
    }

    private function analyzeVariant($variant, $salesStats, $searchStats)
    {
        // الف) محاسبه سرعت فروش (میانگین روزانه در ۳۰ روز اخیر)
        $totalSold30d = $salesStats[$variant->id] ?? 0;
        $velocity = $totalSold30d / 30; // مثلا: 2.5 شاخه در روز

        // ب) محاسبه حجم جستجو (تشخیص Interest)
        // فرض می‌کنیم نام محصول در کوئری جستجو وجود داشته باشد
        // در پایتون از NLP استفاده می‌کنیم، اینجا از str_contains
        $searchVolume = $searchStats->filter(function ($count, $query) use ($variant) {
            return str_contains($query, $variant->name) || str_contains($query, $variant->sku);
        })->sum();

        // ج) فرمول پیش‌بینی تقاضای ۷ روز آینده
        // فرمول: (سرعت فروش * ۷) + (ضریب تبدیل جستجو)
        // فرض: هر ۱۰ جستجو منجر به ۱ سفارش بالقوه می‌شود (Conversion Rate 10%)
        $potentialFromSearch = $searchVolume * 0.1;
        $predictedDemand = ($velocity * 7) + $potentialFromSearch;

        // د) محاسبه نیاز به خرید (Gap Analysis)
        $currentStock = $variant->stock_quantity;
        $restockNeeded = max(0, $predictedDemand - $currentStock);

        // ه) تعیین اولویت و دلیل
        $priority = InventoryPrediction::PRIORITY_LOW;
        $reason = "وضعیت نرمال";

        if ($currentStock == 0 && $predictedDemand > 0) {
            $priority = InventoryPrediction::PRIORITY_CRITICAL;
            $reason = "توقف فروش! (موجودی صفر، تقاضای فعال)";
        } elseif ($restockNeeded > 0) {
            $priority = InventoryPrediction::PRIORITY_HIGH;
            $reason = "خطر اتمام موجودی در هفته آینده";
        } elseif ($searchVolume > 50 && $velocity < 1) {
            $priority = InventoryPrediction::PRIORITY_NORMAL;
            $reason = "تقاضای نهفته (جستجوی بالا ولی فروش کم - قیمت یا توضیحات را چک کنید)";
        }

        // و) ذخیره در دیتابیس
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

    // --- Helper Methods for Optimization ---

    private function getSalesStats(): Collection
    {
        return OrderItem::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->select('product_variant_id', DB::raw('SUM(JSON_EXTRACT(quantity_details, "$.quantity")) as total_qty'))
            ->groupBy('product_variant_id')
            ->pluck('total_qty', 'product_variant_id');
    }

    private function getSearchStats(): Collection
    {
        return Search::query()
            ->where(Search::CREATED_AT, '>=', now()->subDays(7))
            ->select(Search::QUERY, DB::raw('COUNT(*) as count'))
            ->groupBy(Search::QUERY)
            ->pluck('count', Search::QUERY);
    }
}

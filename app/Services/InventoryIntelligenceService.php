<?php

namespace App\Services;

use App\Models\InventoryPrediction;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Search;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryIntelligenceService
{
    /**
     * اجرای تحلیل برای تمام محصولات.
     */
    public function runAnalysis(): void
    {
        $conf = [
            'sales_days' => (int) config('intelligence.inventory.sales_period_days', 30),
            'search_days' => (int) config('intelligence.inventory.search_period_days', 7),
            'prediction_days' => (int) config('intelligence.inventory.prediction_days', 7),
            'conversion_rate' => (float) config('intelligence.inventory.search_conversion_rate', 0.1),
            'conv_min' => (float) config('intelligence.inventory.conversion_rate_min', 0.01),
            'conv_max' => (float) config('intelligence.inventory.conversion_rate_max', 0.5),
            'high_search' => (int) config('intelligence.inventory.high_search_threshold', 50),
            'chunk' => (int) config('intelligence.inventory.chunk_size', 500),
        ];

        // به‌جای truncate (که کل تاریخچه و امکان backtesting را نابود می‌کرد) فقط
        // اسنپ‌شات‌های قدیمی پاک می‌شوند؛ هر اجرا یک اسنپ‌شات جدید با calculated_at می‌سازد.
        $retentionDays = (int) config('intelligence.inventory.prediction_retention_days', 90);
        InventoryPrediction::where(InventoryPrediction::CALCULATED_AT, '<', now()->subDays($retentionDays))->delete();

        $runAt = now();

        // آمار فروش و جستجو یک‌بار محاسبه و در حافظه نگه داشته می‌شود (سبک: فقط شمارش‌ها).
        $salesStats = $this->getSalesStats($conf['sales_days']);
        $searchStats = $this->getSearchStats($conf['search_days']);

        // ضریب فصلی بر اساس ماهِ شمسیِ جاری.
        $seasonalMultiplier = $this->currentSeasonalMultiplier();

        // chunkById تا کل واریانت‌ها هرگز یک‌جا در حافظه بارگذاری نشوند.
        ProductVariant::with('product')->chunkById($conf['chunk'], function (Collection $variants) use ($salesStats, $searchStats, $conf, $seasonalMultiplier, $runAt) {
            $rows = [];
            foreach ($variants as $variant) {
                $row = $this->analyzeVariant($variant, $salesStats, $searchStats, $conf, $seasonalMultiplier, $runAt);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            if ($rows) {
                InventoryPrediction::insert($rows);
            }
        });
    }

    /**
     * @return array<string,mixed>|null ردیفِ آماده‌ی insert یا null اگر نکته‌ای نباشد
     */
    private function analyzeVariant($variant, Collection $salesStats, Collection $searchStats, array $conf, float $seasonalMultiplier, Carbon $runAt): ?array
    {
        // الف) سرعت فروش روزانه (Velocity)
        $totalSold = (float) ($salesStats[$variant->id] ?? 0);
        $velocity = $conf['sales_days'] > 0 ? $totalSold / $conf['sales_days'] : 0.0;

        // ب) حجم جستجوی مرتبط با این واریانت
        $searchVolume = (float) $searchStats->filter(function ($count, $query) use ($variant) {
            return ($variant->name && str_contains($query, $variant->name))
                || ($variant->sku && str_contains($query, $variant->sku));
        })->sum();

        // ج) نرخ تبدیلِ per-product از داده‌ی واقعی (sold / searched)، با fallback به پیش‌فرض.
        $conversionRate = $this->resolveConversionRate($totalSold, $searchVolume, $conf);

        // د) پیش‌بینی تقاضا با تعدیل فصلی
        $potentialFromSearch = $searchVolume * $conversionRate;
        $predictedDemand = (($velocity * $conf['prediction_days']) + $potentialFromSearch) * $seasonalMultiplier;

        // ه) شکاف موجودی
        $currentStock = (int) ($variant->stock_quantity ?? 0);
        $restockNeeded = max(0, $predictedDemand - $currentStock);

        // و) اولویت و دلیل
        $priority = InventoryPrediction::PRIORITY_LOW;
        $reason = 'وضعیت نرمال';

        if ($currentStock == 0 && $predictedDemand > 0.5) {
            $priority = InventoryPrediction::PRIORITY_CRITICAL;
            $reason = 'توقف فروش! (موجودی صفر، تقاضای فعال)';
        } elseif ($restockNeeded > 0) {
            $priority = InventoryPrediction::PRIORITY_HIGH;
            $reason = "خطر اتمام موجودی در {$conf['prediction_days']} روز آینده";
        } elseif ($searchVolume > $conf['high_search'] && $velocity < 1) {
            $priority = InventoryPrediction::PRIORITY_NORMAL;
            $reason = 'تقاضای نهفته (جستجوی بالا ولی عدم ثبت سفارش)';
        }

        // فقط اگر نکته‌ای وجود دارد ثبت می‌شود.
        if (! ($predictedDemand > 0 || $currentStock < 10)) {
            return null;
        }

        return [
            InventoryPrediction::PRODUCT_VARIANT_ID => $variant->id,
            InventoryPrediction::CURRENT_STOCK => $currentStock,
            InventoryPrediction::SALES_VELOCITY_30D => number_format($velocity, 2, '.', ''),
            InventoryPrediction::SEARCH_VOLUME_7D => (int) $searchVolume,
            InventoryPrediction::PREDICTED_DEMAND_NEXT_7D => number_format($predictedDemand, 2, '.', ''),
            InventoryPrediction::RECOMMENDED_RESTOCK_AMOUNT => (int) ceil($restockNeeded),
            InventoryPrediction::PRIORITY => $priority,
            InventoryPrediction::REASON => $reason,
            InventoryPrediction::CALCULATED_AT => $runAt,
        ];
    }

    /**
     * نرخ تبدیلِ واقعیِ هر محصول (فروش/جستجو)، کلَمپ‌شده در بازه‌ی معقول؛
     * در نبودِ داده‌ی کافی به نرخ پیش‌فرض config برمی‌گردد.
     */
    private function resolveConversionRate(float $sold, float $searched, array $conf): float
    {
        if ($searched < 5) {
            return $conf['conversion_rate'];
        }

        $rate = $sold / $searched;

        return max($conf['conv_min'], min($conf['conv_max'], $rate));
    }

    private function currentSeasonalMultiplier(): float
    {
        $multipliers = (array) config('intelligence.inventory.seasonal_multipliers', []);
        if (empty($multipliers)) {
            return 1.0;
        }

        // ماهِ شمسی از Verta؛ در نبود کتابخانه به ماهِ میلادی fallback.
        $month = $this->currentJalaliMonth();

        return (float) ($multipliers[$month] ?? 1.0);
    }

    private function currentJalaliMonth(): int
    {
        if (function_exists('verta')) {
            try {
                return (int) verta()->month;
            } catch (\Throwable $e) {
                // fallthrough
            }
        }

        return (int) now()->month;
    }

    // --- Helper Methods ---

    /**
     * مجموع تعداد فروش هر واریانت در بازه‌ی داده‌شده (group-by صحیح).
     *
     * @return Collection<int,float> keyed by product_variant_id
     */
    private function getSalesStats(int $days): Collection
    {
        return OrderItem::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->select('product_variant_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('product_variant_id')
            ->pluck('total_qty', 'product_variant_id');
    }

    /**
     * @return Collection<string,int> keyed by search query
     */
    private function getSearchStats(int $days): Collection
    {
        return Search::query()
            ->where(Search::CREATED_AT, '>=', now()->subDays($days))
            ->select(Search::QUERY, DB::raw('COUNT(*) as count'))
            ->groupBy(Search::QUERY)
            ->pluck('count', Search::QUERY);
    }
}

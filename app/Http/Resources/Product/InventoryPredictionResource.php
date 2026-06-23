<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\InventoryPrediction;
use Illuminate\Http\Request;

/**
 * @mixin InventoryPrediction
 */
class InventoryPredictionResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // sales_velocity و search_volume هوش رقابتی‌اند (نشان می‌دهند چه چیزی خوب
        // می‌فروشد و چه چیزی پرجستجوست) — فقط برای ادمین برگردانده می‌شوند.
        $isAdmin = $request->user()?->hasRole('admin') ?? false;

        return [
            'id' => $this->id,

            // ۱. دسته‌بندی اطلاعات محصول (تا با بقیه قاطی نشود)
            'product_info' => [
                'name' => $this->variant->product->name ?? 'Unknown',
                'variant' => $this->variant->name ?? '',
                'sku' => $this->variant->sku,
            ],

            // ۲. متریک‌های آماری (Facts) — متریک‌های حساس فقط برای ادمین
            'metrics' => array_filter([
                'current_stock' => (int) $this->current_stock,
                'sales_velocity' => $isAdmin ? (float) $this->sales_velocity_30d : null,
                'search_hits' => $isAdmin ? (int) $this->search_volume_7d : null,
            ], fn ($v) => $v !== null),

            // ۳. خروجی هوش مصنوعی (Predictions)
            'analysis' => [
                'predicted_demand' => (float) $this->predicted_demand_next_7d,
                'restock_amount' => (int) $this->recommended_restock_amount,
            ],

            // ۴. وضعیت و نمایش (UI Logic)
            'status' => [
                'priority_key' => $this->priority,
                'label' => $this->getPriorityLabel($this->priority),
                'color' => $this->getPriorityColor($this->priority),
                'message' => $this->reason,
            ],

            'last_updated' => $this->calculated_at,
        ];
    }

    private function getPriorityLabel($priority): string
    {
        return match ($priority) {
            'critical' => 'بحرانی (توقف فروش)',
            'high' => 'بالا (نیازمند اقدام)',
            'normal' => 'معمولی',
            'low' => 'پایین',
            default => $priority,
        };
    }

    /**
     * تعیین رنگ برای UI
     */
    private function getPriorityColor($priority): string
    {
        return match ($priority) {
            'critical' => '#EF4444', // قرمز
            'high' => '#F59E0B', // نارنجی
            'normal' => '#3B82F6', // آبی
            'low' => '#10B981', // سبز
            default => '#9CA3AF', // طوسی
        };
    }
}

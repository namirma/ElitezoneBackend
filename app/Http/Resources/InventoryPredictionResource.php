<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryPredictionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // ۱. دسته‌بندی اطلاعات محصول (تا با بقیه قاطی نشود)
            'product_info' => [
                'name'    => $this->variant->product->name ?? 'Unknown',
                'variant' => $this->variant->name ?? '',
                'sku'     => $this->variant->sku,
            ],

            // ۲. متریک‌های آماری (Facts)
            'metrics' => [
                'current_stock'  => (int) $this->current_stock,
                'sales_velocity' => (float) $this->sales_velocity_30d, 
                'search_hits'    => (int) $this->search_volume_7d,
            ],

            // ۳. خروجی هوش مصنوعی (Predictions)
            'analysis' => [
                'predicted_demand' => (float) $this->predicted_demand_next_7d,
                'restock_amount'   => (int) $this->recommended_restock_amount,
            ],

            // ۴. وضعیت و نمایش (UI Logic)
            'status' => [
                'priority_key' => $this->priority,
                'label'        => $this->getPriorityLabel($this->priority),
                'color'        => $this->getPriorityColor($this->priority),
                'message'      => $this->reason,
            ],

            'last_updated' => $this->calculated_at,
        ];
    }

    private function getPriorityLabel($priority): string
    {
        return match ($priority) {
            'critical' => 'بحرانی (توقف فروش)',
            'high'     => 'بالا (نیازمند اقدام)',
            'normal'   => 'معمولی',
            'low'      => 'پایین',
            default    => $priority,
        };
    }

    /**
     * تعیین رنگ برای UI
     */
    private function getPriorityColor($priority): string
    {
        return match ($priority) {
            'critical' => '#EF4444', // قرمز
            'high'     => '#F59E0B', // نارنجی
            'normal'   => '#3B82F6', // آبی
            'low'      => '#10B981', // سبز
            default    => '#9CA3AF', // طوسی
        };
    }
}

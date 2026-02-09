<?php

namespace App\Services;

use App\Exceptions\Order\StockUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use Exception;
use Illuminate\Support\Facades\Cache;

class InventoryService
{
    /**
     * بررسی موجودی (بدون کسر کردن)
     */
    public function checkStock(ProductVariant $variant, array|float|int $quantities): bool
    {
        $requiredBaseQty = is_array($quantities)
            ? $this->calculateBaseQuantity($quantities)
            : (float) $quantities;

        return $variant->stock_quantity >= $requiredBaseQty;
    }

    /**
     * کسر موجودی اولیه برای ثبت سفارش
     */
    public function deductStockForOrder(Order $order): void
    {
        $order->load('items');

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    /**
     * متد جدید: اصلاح موجودی بعد از باسکول
     * اگر اختلاف مثبت باشد (سرک بار) -> کسر بیشتر از انبار
     * اگر اختلاف منفی باشد (کسری بار) -> بازگشت به انبار
     * * @param int $variantId شناسه محصول
     * @param float $weightDifference اختلاف وزن (وزن جدید - وزن قدیم)
     */
    public function updateStockAfterWeighbridge(int $variantId, float $weightDifference): void
    {
        // اگر اختلاف صفر بود کاری نکن
        if (abs($weightDifference) < 0.001) {
            return;
        }

        $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();

        if (!$variant) return;

        if ($weightDifference > 0) {
            // سرک بار: مشتری بیشتر برده، پس باید از انبار کم کنیم
            if ($variant->stock_quantity < $weightDifference) {
                // اگر موجودی منفی می‌شود، شاید بخواهید لاگ کنید یا فورس کسر کنید
                // اینجا فعلاً فورس کسر می‌کنیم
            }
            $variant->decrement('stock_quantity', $weightDifference);
        } else {
            // کسری بار: مشتری کمتر برده، باید به انبار برگردانیم
            // مقدار منفی است، پس abs می‌گیریم
            $variant->increment('stock_quantity', abs($weightDifference));
        }
    }

    private function deductItemStock(OrderItem $item): void
    {
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (!$variant) {
            throw new Exception("محصول با شناسه {$item->product_variant_id} یافت نشد.");
        }

        $quantities = is_string($item->quantity_details)
            ? json_decode($item->quantity_details, true)
            : $item->quantity_details;

        $requiredBaseQty = $this->calculateBaseQuantity($quantities);

        if ($variant->stock_quantity < $requiredBaseQty) {
            throw new StockUnavailableException($variant->name);
        }

        $variant->decrement('stock_quantity', $requiredBaseQty);
    }

    /**
     * بازگرداندن کل موجودی (برای کنسلی سفارش)
     */
    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $quantities = is_string($item->quantity_details)
                ? json_decode($item->quantity_details, true)
                : $item->quantity_details;

            $qty = $this->calculateBaseQuantity($quantities);

            ProductVariant::where('id', $item->product_variant_id)
                ->increment('stock_quantity', $qty);
        }
    }

    /**
     * محاسبه مقدار کل بر اساس فرمول ساده: تعداد * مقدار واحد
     */
    private function calculateBaseQuantity(?array $details): float
    {
        if (empty($details)) return 0.0;

        $unitsMap = Cache::remember('all_order_units', 86400, function () {
            return OrderUnit::all()->keyBy('id');
        });

        $totalBaseQty = 0.0;

        foreach ($details as $unitId => $qty) {
            $qty = (float) $qty;
            $unit = $unitsMap->get((int)$unitId);

            if ($unit) {
                $totalBaseQty += $qty * (float)$unit->amount;
            } else {
                $totalBaseQty += $qty;
            }
        }

        return $totalBaseQty;
    }
}

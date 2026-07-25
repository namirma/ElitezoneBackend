<?php

namespace App\Services;

use App\Exceptions\Order\StockUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class InventoryService
{
    /**
     * بررسی موجودی قبل از ثبت سفارش (بدون کسر کردن)
     */
    public function checkStock(ProductVariant $variant, array|float|int $quantities): bool
    {
        $requiredBaseQty = is_array($quantities)
            ? $this->calculateBaseQuantity($quantities, $variant)
            : (float) $quantities;

        return $variant->stock_quantity >= ($requiredBaseQty - 0.0001);
    }

    /**
     * کسر موجودی برای اقلام یک سفارش
     *
     * @throws StockUnavailableException
     */
    public function deductStockForOrder(Order $order): void
    {
        $order->loadMissing(['items.productVariant']);

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    /**
     * اصلاح موجودی بعد از ثبت باسکول (Weighbridge)
     */
    public function updateStockAfterWeighbridge(int $variantId, float $weightDifference): void
    {
        if (abs($weightDifference) < 0.001) {
            return;
        }

        $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();
        if (! $variant) {
            return;
        }

        if ($weightDifference > 0) {
            // کف‌بندی روی صفر — deductItemStock گاردِ موجودی دارد ولی این متد نداشت،
            // پس یک اختلافِ باسکولِ بزرگ‌تر از موجودیِ باقی‌مانده می‌توانست
            // stock_quantity را منفی کند و از آن به بعد هر checkStock را بشکند.
            $variant->decrement('stock_quantity', min($weightDifference, (float) $variant->stock_quantity));
        } else {
            $variant->increment('stock_quantity', abs($weightDifference));
        }
    }

    /**
     * کسر موجودی یک آیتم خاص
     */
    private function deductItemStock(OrderItem $item): void
    {
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (! $variant) {
            throw new ModelNotFoundException(
                __('inventory.errors.product_not_found', ['id' => $item->product_variant_id])
            );
        }

        $amountToDeduct = ($item->weight > 0) ? (float) $item->weight : (float) $item->quantity;

        if ($amountToDeduct <= 0) {
            $quantities = is_string($item->quantity_details)
                ? json_decode($item->quantity_details, true)
                : $item->quantity_details;

            $amountToDeduct = $this->calculateBaseQuantity($quantities, $variant);
        }

        // بررسی موجودی و پرتاب خطای ترجمه‌شده و مرتب
        if ($variant->stock_quantity < ($amountToDeduct - 0.0001)) {
            throw new StockUnavailableException(
                __('inventory.errors.insufficient_stock', [
                    'name' => $variant->name,
                    'requested' => number_format($amountToDeduct),
                    'available' => number_format((float) $variant->stock_quantity),
                ])
            );
        }

        $variant->decrement('stock_quantity', $amountToDeduct);
    }

    /**
     * بازگرداندن موجودی (در صورت لغو سفارش)
     */
    public function restoreStock(Order $order): void
    {
        $order->loadMissing(['items.productVariant']);

        foreach ($order->items as $item) {
            $amountRestored = ($item->weight > 0) ? (float) $item->weight : (float) $item->quantity;

            if ($amountRestored <= 0) {
                $quantities = is_string($item->quantity_details)
                    ? json_decode($item->quantity_details, true)
                    : $item->quantity_details;

                $variant = $item->productVariant;
                $amountRestored = $this->calculateBaseQuantity($quantities, $variant);
            }

            if ($amountRestored > 0) {
                ProductVariant::where('id', $item->product_variant_id)
                    ->increment('stock_quantity', $amountRestored);
            }
        }
    }

    /**
     * محاسبه مقدار پایه (کیلوگرم یا تعداد) از روی جزئیات
     */
    private function calculateBaseQuantity(?array $details, ?ProductVariant $variant = null): float
    {
        if (empty($details)) {
            return 0.0;
        }

        $unitsMap = OrderUnit::allCached();

        $totalBaseQty = 0.0;

        foreach ($details as $unitId => $qty) {
            $qty = (float) $qty;
            $unit = $unitsMap->get((int) $unitId);

            if ($unit) {
                $totalBaseQty += $qty * (float) $unit->amount;
            } else {
                $variantWeight = ($variant && $variant->weight > 0) ? (float) $variant->weight : 1.0;
                $totalBaseQty += $qty * $variantWeight;
            }
        }

        return $totalBaseQty;
    }
}

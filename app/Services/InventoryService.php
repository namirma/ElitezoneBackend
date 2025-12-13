<?php

namespace App\Services;

use App\Exceptions\Order\StockUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit; // <--- ایمپورت مدل واحد
use App\Models\ProductVariant;
use Exception;
use Illuminate\Support\Facades\Cache; // <--- برای کش کردن واحدها

class InventoryService
{

    public function deductStockForOrder(Order $order): void
    {
        $order->load('items');

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    private function deductItemStock(OrderItem $item): void
    {
        // 1. قفل کردن رکورد برای جلوگیری از Race Condition
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (!$variant) {
            throw new Exception("محصول با شناسه {$item->product_variant_id} یافت نشد.");
        }

        $requiredBaseQty = $this->calculateBaseQuantity($item->quantity_details);

        if ($variant->stock_quantity < $requiredBaseQty) {
            throw new StockUnavailableException($variant->name);
        }

        $variant->decrement('stock_quantity', $requiredBaseQty);
    }

    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $qty = $this->calculateBaseQuantity($item->quantity_details);

            ProductVariant::where('id', $item->product_variant_id)
                ->increment('stock_quantity', $qty);
        }
    }

    /**
     * محاسبه مقدار کل بر اساس ضرایب واحدها
     * (جایگزین array_sum ساده قبلی)
     */
    private function calculateBaseQuantity(?array $details): float
    {
        if (empty($details)) return 0.0;

        $unitsMap = Cache::remember('order_units_map', 86400, function () {
            return OrderUnit::all()->keyBy('id');
        });

        $totalBaseQty = 0.0;

        foreach ($details as $unitId => $qty) {
            $unit = $unitsMap->get((int)$unitId);

            if ($unit) {
                $totalBaseQty += (float)$qty * (float)$unit->amount;
            }
        }

        return $totalBaseQty;
    }
}

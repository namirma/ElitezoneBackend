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
     * این متد در CartController و AddToCartAction استفاده می‌شود
     */
    public function checkStock(ProductVariant $variant, array|float|int $quantities): bool
    {
        $requiredBaseQty = is_array($quantities)
            ? $this->calculateBaseQuantity($quantities)
            : (float) $quantities;

        return $variant->stock_quantity >= $requiredBaseQty;
    }

    /**
     * کسر موجودی برای سفارش نهایی
     */
    public function deductStockForOrder(Order $order): void
    {
        $order->load('items');

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    private function deductItemStock(OrderItem $item): void
    {
        // قفل کردن رکورد برای جلوگیری از Race Condition
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (!$variant) {
            throw new Exception("محصول با شناسه {$item->product_variant_id} یافت نشد.");
        }

        $quantities = $item->quantities;

        $requiredBaseQty = $this->calculateBaseQuantity($quantities);

        if ($variant->stock_quantity < $requiredBaseQty) {
            throw new StockUnavailableException($variant->name);
        }

        $variant->decrement('stock_quantity', $requiredBaseQty);
    }

    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $quantities = $item->quantities; // فرض بر این است که OrderItem کست array دارد
            $qty = $this->calculateBaseQuantity($quantities);

            ProductVariant::where('id', $item->product_variant_id)
                ->increment('stock_quantity', $qty);
        }
    }

    /**
     * محاسبه مقدار کل بر اساس ضرایب واحدها
     */
    private function calculateBaseQuantity(?array $details): float
    {
        if (empty($details)) return 0.0;

        // کش کردن واحدها برای پرهیز از کوکی‌های تکراری دیتابیس
        $unitsMap = Cache::remember('order_units_map', 86400, function () {
            return OrderUnit::all()->keyBy('id');
        });

        $totalBaseQty = 0.0;

        foreach ($details as $unitId => $qty) {
            $unit = $unitsMap->get((int)$unitId);

            if ($unit) {
                $totalBaseQty += (float)$qty * (float)$unit->amount;
            } else {
                $totalBaseQty += (float)$qty;
            }
        }

        return $totalBaseQty;
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductVariant;
use Exception;

class InventoryService
{
    /**
     * @param Order $order
     * @throws Exception
     */
    public function deductStockForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            /** @var ProductVariant $variant */
            $variant = ProductVariant::where('id', $item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if (!$variant) {
                throw new Exception("محصول با شناسه {$item->product_variant_id} یافت نشد.");
            }

            // ۲. بررسی موجودی لحظه‌ای
            if ($variant->stock_quantity < $item->quantity) {
                throw new Exception("موجودی محصول «{$variant->name}» کافی نیست. (درخواست: {$item->quantity}، موجود: {$variant->stock_quantity})");
            }

            // ۳. کسر موجودی
            $variant->decrement('stock_quantity', $item->quantity);
        }
    }

    /**
     * بازگرداندن موجودی (مناسب برای زمان لغو سفارش).
     *
     * @param Order $order
     * @return void
     */
    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            ProductVariant::where('id', $item->product_variant_id)
                ->increment('stock_quantity', $item->quantity);
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\Order\StockUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

class InventoryService
{
    /**
     * بررسی موجودی قبل از ثبت سفارش (بدون کسر کردن)
     * * @param ProductVariant $variant
     * @param array|float|int $quantities
     * @return bool
     */
    public function checkStock(ProductVariant $variant, array|float|int $quantities): bool
    {
        $requiredBaseQty = is_array($quantities)
            ? $this->calculateBaseQuantity($quantities, $variant)
            : (float) $quantities;

        // مقایسه با تلورانس کوچک برای اعداد اعشاری
        return $variant->stock_quantity >= ($requiredBaseQty - 0.0001);
    }

    /**
     * کسر موجودی برای اقلام یک سفارش
     * * @param Order $order
     * @throws StockUnavailableException
     */
    public function deductStockForOrder(Order $order): void
    {
        // اطمینان از لود شدن آیتم‌ها
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    /**
     * اصلاح موجودی بعد از ثبت باسکول (Weighbridge)
     * * @param int $variantId
     * @param float $weightDifference
     */
    public function updateStockAfterWeighbridge(int $variantId, float $weightDifference): void
    {
        // اگر اختلاف ناچیز است، صرف‌نظر کن
        if (abs($weightDifference) < 0.001) {
            return;
        }

        // دریافت رکورد با قفل برای جلوگیری از تداخل
        $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();

        if (!$variant) {
            // لاگ کردن یا پرتاب خطا بسته به سیاست سیستم
            return;
        }

        if ($weightDifference > 0) {

            $variant->decrement('stock_quantity', $weightDifference);
        } else {
            // کسری بار (Underweight): مشتری بار کمتری برده است -> بازگشت به انبار
            $variant->increment('stock_quantity', abs($weightDifference));
        }
    }

    /**
     * کسر موجودی یک آیتم خاص
     */
    private function deductItemStock(OrderItem $item): void
    {
        // قفل کردن رکورد واریانت
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (!$variant) {
            throw new ModelNotFoundException("محصول با شناسه {$item->product_variant_id} یافت نشد.");
        }

        // تصمیم‌گیری هوشمند: کسر وزن یا تعداد؟
        // اگر در دیتابیس وزن ذخیره شده باشد (برای میلگرد و ...)، اولویت با وزن است.
        // اگر وزن 0 بود (کالای عددی)، از تعداد استفاده می‌کنیم.
        $amountToDeduct = ($item->weight > 0) ? (float)$item->weight : (float)$item->quantity;

        // اگر به هر دلیلی مقادیر ذخیره شده در آیتم صفر بود، محاسبه مجدد انجام می‌دهیم
        if ($amountToDeduct <= 0) {
            $quantities = is_string($item->quantity_details)
                ? json_decode($item->quantity_details, true)
                : $item->quantity_details;

            $amountToDeduct = $this->calculateBaseQuantity($quantities, $variant);
        }

        // بررسی موجودی
        if ($variant->stock_quantity < ($amountToDeduct - 0.0001)) {
            throw new StockUnavailableException(
                "موجودی محصول '{$variant->name}' کافی نیست. (درخواست: {$amountToDeduct}، موجود: {$variant->stock_quantity})"
            );
        }

        // انجام کسر موجودی
        $variant->decrement('stock_quantity', $amountToDeduct);
    }

    /**
     * بازگرداندن موجودی (در صورت لغو سفارش)
     */
    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $amountRestored = ($item->weight > 0) ? (float)$item->weight : (float)$item->quantity;

            // اگر به هر دلیلی صفر بود، محاسبه مجدد
            if ($amountRestored <= 0) {
                $quantities = is_string($item->quantity_details)
                    ? json_decode($item->quantity_details, true)
                    : $item->quantity_details;

                // برای محاسبه دقیق نیاز به مدل واریانت داریم، اما اینجا برای سرعت
                // بهتر است از داده‌های آیتم استفاده کنیم. اگر موجود نبود، کوئری می‌زنیم.
                $variant = $item->productVariant;
                $amountRestored = $this->calculateBaseQuantity($quantities, $variant);
            }

            ProductVariant::where('id', $item->product_variant_id)
                ->increment('stock_quantity', $amountRestored);
        }
    }

    /**
     * محاسبه مقدار پایه (کیلوگرم یا تعداد) از روی جزئیات
     */
    private function calculateBaseQuantity(?array $details, ?ProductVariant $variant = null): float
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
                // اگر واحد وجود داشت (مثلا بندیل)، وزن آن را ضربدر تعداد کن
                $totalBaseQty += $qty * (float)$unit->amount;
            } else {
                // اگر واحد خاصی نبود، سعی کن از وزن خود محصول استفاده کنی
                $variantWeight = ($variant && $variant->weight > 0) ? (float)$variant->weight : 1.0;

                // اگر وزن محصول تعریف نشده بود، فرض بر تعداد ساده است
                // (در سیستم‌های ترکیبی این نقطه حساس است)
                $totalBaseQty += $qty * $variantWeight;
            }
        }

        return $totalBaseQty;
    }
}

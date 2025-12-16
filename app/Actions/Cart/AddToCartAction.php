<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddToCartAction
{
    /**
     * متد اصلی اجرایی
     */
    public function execute(Cart $cart, array $data): Cart
    {
        // 1. بارگذاری واریانت و کارخانه
        $variant = ProductVariant::with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])
            ->findOrFail($data['variant_id']);

        // 2. کش کردن نگاشت واحدها برای محاسبات سریع
        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cart, $variant, $data, $orderUnitsMap) {

            // مشخصات کلیدی برای پیدا کردن آیتم تکراری
            $identifiers = [
                CartItem::CART_ID             => $cart->id,
                CartItem::PRODUCT_VARIANT_ID  => $variant->id,
                CartItem::FACTORY_ID          => $data['factory_id'],
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                CartItem::SHIPPING_METHOD_ID  => $data['shipping_method_id'],
            ];

            // آیا این آیتم از قبل در سبد هست؟
            $existingItem = $cart->items()->where($identifiers)->first();

            // 3. اعتبارسنجی محدودیت‌های هر واحد (مثلاً حداکثر 50 کیسه)
            // منطق سابق: validateOrderUnitCounts
            $this->validateOrderUnitCounts($variant, $data['quantities'], $cart, $existingItem);

            if ($existingItem) {
                // سناریو الف: آیتم هست -> ادغام تعداد
                $mergedQuantities = $this->mergeQuantities($existingItem->quantities, $data['quantities']);

                // 4. اعتبارسنجی محدودیت‌های مجموع واریانت (Variant Aggregate Limits)
                // منطق سابق: checkVariantAggregateLimits
                $this->checkVariantAggregateLimits($variant, $mergedQuantities, $orderUnitsMap);

                $existingItem->update([
                    CartItem::QUANTITIES => $mergedQuantities,
                    // اگر لوکیشن جدید هم آمده باشد، آپدیت می‌شود
                    CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                ]);

            } else {
                // سناریو ب: آیتم جدید
                // 4. اعتبارسنجی محدودیت‌های مجموع واریانت
                $this->checkVariantAggregateLimits($variant, $data['quantities'], $orderUnitsMap);

                CartItem::create(array_merge($identifiers, [
                    CartItem::QUANTITIES => $data['quantities'],
                    CartItem::PRICE      => 0, // قیمت بعداً محاسبه می‌شود
                    CartItem::FINAL_PRICE => 0,
                ]));
            }

            // 5. اعتبارسنجی محدودیت‌های کل خط محصول (Product Line Aggregate Limits)
            // مثلاً: مجموع تمام میلگردهای ۱۶ و ۱۸ نباید کمتر از ۲ تن باشد
            // منطق سابق: checkProductLineAggregateLimits
            // نکته: باید سبد را رفرش کنیم تا آیتم جدید را ببیند
            $freshCart = $cart->fresh(['items.productVariant.product', 'items.productVariant.product.aggregateLimitOrderUnit']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            // اگر فیلتر قیمت ارسال شده، سینک کن
            if (isset($data['filter_categories'])) {
                $cart->priceFilterCategories()->sync($data['filter_categories']);
            }

            return $cart->load('items');
        });
    }

    // =========================================================================
    //  بخش دوم: متدهای کمکی (همان منطق‌های کپی شده از Trait)
    // =========================================================================

    protected function getOrderUnitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, function () {
            return OrderUnit::all()->keyBy(OrderUnit::ID);
        });
    }

    protected function mergeQuantities(array $current, array $new): array
    {
        $merged = $current;
        foreach ($new as $unitId => $qty) {
            $key = (string) $unitId;
            $currentVal = $merged[$key] ?? ($merged[(int)$unitId] ?? 0);
            $merged[$key] = (float)$currentVal + (float)$qty;
        }
        return $merged;
    }

    /**
     * تبدیل مقادیر واحدها به یک واحد پایه (مثلاً تبدیل همه به کیلوگرم)
     */
    protected function convertQuantitiesToTargetUnitValue(array $itemQuantities, ?OrderUnit $targetLimitOrderUnit, Collection $orderUnitsMap): ?float
    {
        if (!$targetLimitOrderUnit || $targetLimitOrderUnit->amount == 0) {
            return null;
        }

        $totalValueInBase = 0;
        foreach ($itemQuantities as $unitId => $count) {
            $currentOrderUnit = $orderUnitsMap->get((int)$unitId);
            if ($currentOrderUnit && $currentOrderUnit->amount > 0) {
                $totalValueInBase += (float)$count * $currentOrderUnit->amount;
            }
        }

        return $totalValueInBase / $targetLimitOrderUnit->amount;
    }

    /**
     * بررسی سقف تعداد مجاز برای هر واحد خاص
     * (مثلاً از این نوع بسته‌بندی فقط ۵۰ تا مجاز است)
     */
    protected function validateOrderUnitCounts(ProductVariant $variant, array $quantities, Cart $cart, ?CartItem $ignoredItem = null): void
    {
        if (!$variant->relationLoaded('product')) $variant->load('product.orderUnits');

        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($quantities as $unitId => $qty) {
            $unitId = (int)$unitId;
            $qty = (float)$qty;

            $unit = $unitMap->get($unitId);
            if (!$unit) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["واحد سفارش با شناسه {$unitId} نامعتبر است."]]);
            }

            if ($qty < 0) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["تعداد واحد '{$unit->name}' نمی‌تواند منفی باشد."]]);
            }

            $maxCount = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            // محاسبه تعداد فعلی در سبد (به جز آیتم فعلی اگر ویرایش است)
            $currentInCart = $cart->items
                ->when($ignoredItem, fn($c) => $c->where('id', '!=', $ignoredItem->id))
                ->where('product_variant_id', $variant->id)
                ->sum(fn($item) => $item->quantities[(string)$unitId] ?? ($item->quantities[(int)$unitId] ?? 0));

            if (($currentInCart + $qty) > $maxCount) {
                throw ValidationException::withMessages(['quantities.' . $unitId => [
                    "حداکثر مجاز برای '{$unit->name}' برابر {$maxCount} است. موجودی فعلی سبد: {$currentInCart}"
                ]]);
            }
        }
    }

    /**
     * بررسی محدودیت‌های مجموع برای یک واریانت
     * (مثلاً حداقل خرید این میلگرد باید ۱۰۰ کیلو باشد، حتی اگر با واحدهای مختلف خریده شود)
     */
    protected function checkVariantAggregateLimits(ProductVariant $variant, array $quantities, Collection $orderUnitsMap): void
    {
        $minValue = $variant->min_order_value;
        $maxValue = $variant->max_order_value;
        $limitUnitId = $variant->limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;

        if (!$variant->relationLoaded('limitOrderUnit')) $variant->load('limitOrderUnit');

        $limitUnit = $variant->limitOrderUnit;
        if (!$limitUnit) return; 

        $totalValue = $this->convertQuantitiesToTargetUnitValue($quantities, $limitUnit, $orderUnitsMap);

        if ($totalValue === null) return;

        $variantName = $variant->name;
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداقل سفارش برای '{$variantName}' باید {$minValue} {$unitName} باشد. فعلی: " . round($totalValue, 2)
            ]]);
        }

        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداکثر سفارش برای '{$variantName}' برابر {$maxValue} {$unitName} است."
            ]]);
        }
    }

    /**
     * بررسی محدودیت‌های کل گروه محصول
     * (مثلاً مجموع خرید تمام پروفیل‌های Z باید حداقل ۲ تن باشد)
     */
    protected function checkProductLineAggregateLimits(Cart $cart, Product $product, Collection $orderUnitsMap): void
    {
        $minValue = $product->aggregate_min_order_value;
        $maxValue = $product->aggregate_max_order_value;
        $limitUnitId = $product->aggregate_limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;

        if (!$product->relationLoaded('aggregateLimitOrderUnit')) $product->load('aggregateLimitOrderUnit');

        $limitUnit = $product->aggregateLimitOrderUnit;
        if (!$limitUnit || $limitUnit->amount == 0) return;

        $totalValueInBase = 0;

        // حلقه روی تمام آیتم‌های سبد برای پیدا کردن محصولات مشابه
        foreach ($cart->items as $item) {
            if ($item->productVariant->product_id === $product->id) {
                foreach ($item->quantities as $unitId => $count) {
                    $unit = $orderUnitsMap->get((int)$unitId);
                    if ($unit && $unit->amount > 0) {
                        $totalValueInBase += (float)$count * $unit->amount;
                    }
                }
            }
        }

        $totalValue = $totalValueInBase / $limitUnit->amount;
        $productName = $product->name;
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداقل مجموع سفارش برای گروه '{$productName}' باید {$minValue} {$unitName} باشد. فعلی: " . round($totalValue, 2)
            ]]);
        }

        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداکثر مجموع سفارش برای گروه '{$productName}' برابر {$maxValue} {$unitName} است."
            ]]);
        }
    }
}

<?php

namespace App\Actions\Cart;

use App\Events\CartItemChanged;
use App\Models\Cart;
use App\Models\CartEvent;
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
    public function execute(Cart $cart, array $data): Cart
    {
        $variant = ProductVariant::with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])
            ->findOrFail($data['product_variant_id']);

        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cart, $variant, $data, $orderUnitsMap) {

            // شناساگرهای آیتم
            $identifiers = [
                CartItem::CART_ID             => $cart->id,
                CartItem::PRODUCT_VARIANT_ID  => $variant->id,
                CartItem::FACTORY_ID          => $data['factory_id'],
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                CartItem::SHIPPING_METHOD_ID  => $data['shipping_method_id'],
            ];

            // بررسی وجود آیتم
            $existingItem = $cart->items()->where($identifiers)->first();

            // محاسبه مقادیر نهایی این آیتم (چه جدید باشد چه آپدیت)
            $itemFinalQuantities = $data['quantities'];
            if ($existingItem) {
                $itemFinalQuantities = $this->mergeQuantities($existingItem->quantities, $data['quantities']);
            }

            // محاسبه مجموع کل این واریانت در سبد (برای چک کردن لیمیت‌ها)
            // اینجا از $itemFinalQuantities استفاده می‌کنیم تا منطق تکرار نشود
            $totalVariantQuantities = $this->calculateTotalVariantQuantities($cart, $variant->id, $existingItem, $itemFinalQuantities);

            // اعتبارسنجی‌ها
            $this->validateOrderUnitCounts($variant, $totalVariantQuantities);
            $this->checkVariantAggregateLimits($variant, $totalVariantQuantities, $orderUnitsMap);

            // اعمال تغییرات در دیتابیس
            if ($existingItem) {
                $existingItem->update([
                    CartItem::QUANTITIES => $itemFinalQuantities,
                    CartItem::FACTORY_LOCATION_ID => $data['location_id'], // آپدیت لوکیشن در صورت نیاز
                ]);
            } else {
                CartItem::create(array_merge($identifiers, [
                    CartItem::QUANTITIES => $itemFinalQuantities,
                    CartItem::PRICE      => 0,
                    CartItem::FINAL_PRICE => 0,
                ]));
            }

            // اعتبارسنجی محدودیت‌های کل خط محصول
            $freshCart = $cart->fresh(['items.productVariant.product', 'items.productVariant.product.aggregateLimitOrderUnit']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            // سینک فیلترها
            if (isset($data['filter_categories'])) {
                $cart->priceFilterCategories()->sync($data['filter_categories']);
            }

            // ایونت
            $quantityAdded = array_sum($data['quantities']);
            $currentPriceSnapshot = $variant->price ?? null;

            CartItemChanged::dispatch(
                $cart,
                $variant,
                CartEvent::ACTION_ADD,
                (float) $quantityAdded,
                $currentPriceSnapshot
            );

            return $cart->load('items');
        });
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    protected function getOrderUnitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, fn() => OrderUnit::all()->keyBy(OrderUnit::ID));
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
     * محاسبه مجموع کل موجودی یک واریانت در سبد
     * @param array $targetItemQuantities مقدار نهایی آیتمی که در حال افزودن/ویرایش آن هستیم
     */
    protected function calculateTotalVariantQuantities(Cart $cart, int $variantId, ?CartItem $existingItem, array $targetItemQuantities): array
    {
        $totals = [];

        // 1. اضافه کردن مقادیر سایر آیتم‌های این واریانت (مثلاً در انبارهای دیگر)
        foreach ($cart->items as $item) {
            // فقط آیتم‌های همین واریانت
            if ($item->product_variant_id !== $variantId) continue;

            // آیتم فعلی را نادیده بگیر (چون مقدار نهایی‌اش را جدا داریم)
            if ($existingItem && $item->id === $existingItem->id) continue;

            foreach ($item->quantities as $u => $q) {
                $k = (string)$u;
                $totals[$k] = ($totals[$k] ?? 0) + $q;
            }
        }

        // 2. اضافه کردن مقدار نهایی آیتم فعلی
        foreach ($targetItemQuantities as $u => $q) {
            $k = (string)$u;
            $totals[$k] = ($totals[$k] ?? 0) + $q;
        }

        return $totals;
    }

    protected function convertQuantitiesToTargetUnitValue(array $itemQuantities, ?OrderUnit $targetLimitOrderUnit, Collection $orderUnitsMap): ?float
    {
        if (!$targetLimitOrderUnit || $targetLimitOrderUnit->amount == 0) return null;

        $totalValueInBase = 0;
        foreach ($itemQuantities as $unitId => $count) {
            $currentOrderUnit = $orderUnitsMap->get((int)$unitId);
            if ($currentOrderUnit && $currentOrderUnit->amount > 0) {
                $totalValueInBase += (float)$count * $currentOrderUnit->amount;
            }
        }

        return $totalValueInBase / $targetLimitOrderUnit->amount;
    }

    protected function validateOrderUnitCounts(ProductVariant $variant, array $totalQuantities): void
    {
        if (!$variant->relationLoaded('product')) $variant->load('product.orderUnits');
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($totalQuantities as $unitId => $qty) {
            $unit = $unitMap->get((int)$unitId);
            if (!$unit) continue;

            $maxCount = $unit->pivot->max_quantity ?? PHP_INT_MAX;
            if ($qty > $maxCount) {
                throw ValidationException::withMessages(['quantities.' . $unitId => [
                    "حداکثر مجاز برای '{$unit->name}' برابر {$maxCount} است. مجموع در سبد: {$qty}"
                ]]);
            }
        }
    }

    protected function checkVariantAggregateLimits(ProductVariant $variant, array $totalQuantities, Collection $orderUnitsMap): void
    {
        $minValue = $variant->min_order_value;
        $maxValue = $variant->max_order_value;
        $limitUnitId = $variant->limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;

        if (!$variant->relationLoaded('limitOrderUnit')) $variant->load('limitOrderUnit');
        $limitUnit = $variant->limitOrderUnit;
        if (!$limitUnit) return;

        $totalValue = $this->convertQuantitiesToTargetUnitValue($totalQuantities, $limitUnit, $orderUnitsMap);
        if ($totalValue === null) return;

        $unitName = $limitUnit->name;
        $valRounded = round($totalValue, 2);

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداقل سفارش '{$variant->name}' باید {$minValue} {$unitName} باشد. فعلی: {$valRounded}"
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداکثر سفارش '{$variant->name}' برابر {$maxValue} {$unitName} است. فعلی: {$valRounded}"
            ]]);
        }
    }

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
        $valRounded = round($totalValue, 2);
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداقل مجموع سفارش گروه '{$product->name}' باید {$minValue} {$unitName} باشد. فعلی: {$valRounded}"
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداکثر مجموع سفارش گروه '{$product->name}' برابر {$maxValue} {$unitName} است."
            ]]);
        }
    }
}

<?php

namespace App\Actions\Cart;

use App\Events\CartItemChanged;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddToCartAction
{
    public function __construct(
        protected PriceCalculationService $priceService,
        protected PricingRoleResolver $roleResolver
    ) {}

    public function execute(Cart $cart, array $data): Cart
    {
        $variant = ProductVariant::with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])
            ->findOrFail($data['product_variant_id']);

        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cart, $variant, $data, $orderUnitsMap) {

            // ۱. شناسایی آیتم تکراری
            $identifiers = [
                CartItem::CART_ID             => $cart->id,
                CartItem::PRODUCT_VARIANT_ID  => $variant->id,
                CartItem::FACTORY_ID          => $data['factory_id'],
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                CartItem::SHIPPING_METHOD_ID  => $data['shipping_method_id'],
            ];

            $existingItem = $cart->items()->where($identifiers)->first();

            // ۲. ادغام تعداد (Merge Logic)
            $itemFinalQuantities = $data['quantities'];
            if ($existingItem) {
                $itemFinalQuantities = $this->mergeQuantities($existingItem->quantities, $data['quantities']);
            }

            // ۳. چک کردن محدودیت‌ها
            $totalVariantQuantities = $this->calculateTotalVariantQuantities($cart, $variant->id, $existingItem, $itemFinalQuantities);
            $this->validateOrderUnitCounts($variant, $totalVariantQuantities);
            $this->checkVariantAggregateLimits($variant, $totalVariantQuantities, $orderUnitsMap);

            // ۴. محاسبه مقادیر فیزیکی (وزن و تعداد کل) برای ذخیره در دیتابیس
            $calculatedPhys = $this->calculatePhysicalAttributes($itemFinalQuantities, $orderUnitsMap, $variant);

            // ۵. محاسبه قیمت دقیق (برای اینکه قیمت صفر نباشد)
            $priceData = $this->calculateItemPrice($cart, $variant, $data, $calculatedPhys['weight']);

            // ۶. آماده‌سازی داده‌ها برای ذخیره
            $saveData = [
                CartItem::QUANTITIES => $itemFinalQuantities,
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                'weight'   => $calculatedPhys['weight'],
                'quantity' => $calculatedPhys['quantity'],
                'unit_name' => $calculatedPhys['unit_name'],
                CartItem::PRICE       => $priceData['unit_price'],
                CartItem::FINAL_PRICE => $priceData['unit_price'], 
                CartItem::VAT_AMOUNT  => $priceData['vat_amount'],
                CartItem::TOTAL_WITH_VAT => $priceData['total_with_vat'],
            ];

            // ۷. ذخیره در دیتابیس
            if ($existingItem) {
                $existingItem->update($saveData);
            } else {
                CartItem::create(array_merge($identifiers, $saveData));
            }

            // ۸. چک کردن لیمیت‌های کلی محصول
            $freshCart = $cart->fresh(['items.productVariant.product']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            // ۹. سینک فیلترها
            if (isset($data['filter_categories'])) {
                $cart->priceFilterCategories()->sync($data['filter_categories']);
            }

            // ۱۰. ایونت
            $quantityAdded = array_sum($data['quantities']);
            CartItemChanged::dispatch(
                $cart,
                $variant,
                CartEvent::ACTION_ADD,
                (float) $quantityAdded,
                $variant->price // Snapshot
            );

            // بازگرداندن سبد خرید با تمام روابط لازم برای نمایش صحیح قیمت
            return $cart->load([
                'items.productVariant',
                'items.factory',
                'items.shippingMethod',
                'customerType',
                'badge'
            ]);
        });
    }

    // =========================================================================
    //  New Helpers (Calculation Logic)
    // =========================================================================

    /**
     * محاسبه وزن و تعداد کل برای ذخیره در ستون‌های جدید
     */
   protected function calculatePhysicalAttributes(array $quantities, Collection $orderUnitsMap, ProductVariant $variant): array
    {
        $totalWeight = 0.0;
        $totalQuantity = 0.0;
        $primaryUnitName = null;

        foreach ($quantities as $unitId => $qty) {
            $qty = (float) $qty;
            $totalQuantity += $qty;

            $unit = $orderUnitsMap->get((int)$unitId);

            if (!$primaryUnitName && $unit) {
                $primaryUnitName = $unit->name;
            }

            if ($unit) {
                $totalWeight += $qty * (float) $unit->amount;
            } else {
                $variantWeight = (float) ($variant->weight ?? 0);
                if ($variantWeight <= 0) $variantWeight = 1;
                $totalWeight += $qty * $variantWeight;
            }
        }

        return [
            'weight'    => $totalWeight,
            'quantity'  => $totalQuantity,
            'unit_name' => $primaryUnitName ?? 'عدد'
        ];
    }

    /**
     * محاسبه قیمت در لحظه افزودن به سبد
     */
    protected function calculateItemPrice(Cart $cart, ProductVariant $variant, array $data, float $totalWeight): array
    {
        // پیدا کردن نقش و فیلترهای کاربر
        $user = $cart->user; // فرض بر این است که کارت User دارد
        $roleCode = $user ? $this->roleResolver->resolve($user) : 'employer_without_consultant';

        $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();
        $activeFilter = PriceFilterCategory::where('code', $roleCode)->first();
        $filterIds = $activeFilter ? [$activeFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();

        // محاسبه قیمت واحد
        $pricing = $this->priceService->calculatePrice(
            variant: $variant,
            factory: \App\Models\Factory::find($data['factory_id']), // نیاز به مدل فکتوری داریم
            location: \App\Models\FactoryLocation::find($data['location_id']),
            customerType: $customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterIds,
            withVAT: true,
            filterCategoryCode: $roleCode
        );

        // محاسبه قیمت کل سطر
        $lineTotal = $totalWeight * $pricing->finalPrice;
        $lineVat   = $totalWeight * $pricing->vatAmount;

        return [
            'unit_price'     => $pricing->finalPrice,
            'vat_amount'     => $lineVat, // مالیات کل سطر
            'total_with_vat' => $lineTotal + $lineVat
        ];
    }

    // =========================================================================
    //  Existing Helpers (Refined)
    // =========================================================================

    protected function getOrderUnitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, fn() => OrderUnit::all()->keyBy(OrderUnit::ID));
    }

    protected function mergeQuantities(array $current, array $new): array
    {
        $merged = $current;
        foreach ($new as $unitId => $qty) {
            // کست به استرینگ برای جلوگیری از تغییر ساختار آرایه توسط PHP
            $key = (string) $unitId;

            // پیدا کردن مقدار قبلی (با ساپورت کلید استرینگ و عددی)
            $currentVal = 0;
            if (isset($merged[$key])) {
                $currentVal = $merged[$key];
            } elseif (isset($merged[(int)$unitId])) {
                $currentVal = $merged[(int)$unitId];
                // حذف کلید عددی برای یکدست شدن
                unset($merged[(int)$unitId]);
            }

            $merged[$key] = (float)$currentVal + (float)$qty;
        }
        return $merged;
    }

    protected function calculateTotalVariantQuantities(Cart $cart, int $variantId, ?CartItem $existingItem, array $targetItemQuantities): array
    {
        $totals = [];

        foreach ($cart->items as $item) {
            if ($item->product_variant_id !== $variantId) continue;
            if ($existingItem && $item->id === $existingItem->id) continue;

            $q = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
            if (!is_array($q)) continue;

            foreach ($q as $u => $val) {
                $k = (string)$u;
                $totals[$k] = ($totals[$k] ?? 0) + $val;
            }
        }

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

        // اصلاح کلید مپ برای دسترسی سریع‌تر
        $unitMap = $variant->product->orderUnits->keyBy('id');

        foreach ($totalQuantities as $unitId => $qty) {
            $unit = $unitMap->get((int)$unitId);
            if (!$unit) continue;

            // دسترسی به pivot
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
                // اطمینان از اینکه quantities آرایه است
                $q = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
                if (!is_array($q)) continue;

                foreach ($q as $unitId => $count) {
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

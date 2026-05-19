<?php

namespace App\Actions\Cart;

use App\Events\CartItemChanged;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
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
        protected PricingRoleResolver $roleResolver,
        protected InventoryService $inventoryService
    ) {}

    public function execute(Cart $cart, array $data): Cart
    {
        $variant = ProductVariant::with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])
            ->findOrFail($data['product_variant_id']);

        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cart, $variant, $data, $orderUnitsMap) {

            $identifiers = [
                CartItem::CART_ID => $cart->id,
                CartItem::PRODUCT_VARIANT_ID => $variant->id,
                CartItem::FACTORY_ID => $data['factory_id'],
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                CartItem::SHIPPING_METHOD_ID => $data['shipping_method_id'],
            ];

            $existingItem = $cart->items()->where($identifiers)->first();

            $itemFinalQuantities = $data['quantities'];
            if ($existingItem) {
                $itemFinalQuantities = $this->mergeQuantities($existingItem->quantities, $data['quantities']);
            }

            if (! $this->inventoryService->checkStock($variant, $itemFinalQuantities)) {
                throw ValidationException::withMessages([
                    'quantities' => "موجودی محصول '{$variant->product->name}' کافی نیست. (موجودی فعلی: {$variant->stock_quantity})",
                ]);
            }

            $totalVariantQuantities = $this->calculateTotalVariantQuantities($cart, $variant->id, $existingItem, $itemFinalQuantities);
            $this->validateOrderUnitCounts($variant, $totalVariantQuantities);
            $this->checkVariantAggregateLimits($variant, $totalVariantQuantities, $orderUnitsMap);

            $calculatedPhys = $this->calculatePhysicalAttributes($itemFinalQuantities, $orderUnitsMap, $variant);

            $priceData = $this->calculateItemPrice($cart, $variant, $data, $calculatedPhys['weight']);

            $saveData = [
                CartItem::QUANTITIES => $itemFinalQuantities,
                CartItem::FACTORY_LOCATION_ID => $data['location_id'],
                'weight' => $calculatedPhys['weight'],
                'quantity' => $calculatedPhys['quantity'],
                'unit_name' => $calculatedPhys['unit_name'],
                CartItem::PRICE => $priceData['unit_price'],
                CartItem::FINAL_PRICE => $priceData['unit_price'],
                CartItem::VAT_AMOUNT => $priceData['vat_amount'],
                CartItem::TOTAL_WITH_VAT => $priceData['total_with_vat'],
            ];

            if ($existingItem) {
                $existingItem->update($saveData);
            } else {
                CartItem::create(array_merge($identifiers, $saveData));
            }

            $freshCart = $cart->fresh(['items.productVariant.product']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            if (isset($data['filter_categories'])) {
                $cart->priceFilterCategories()->sync($data['filter_categories']);
            }

            // ۱۱. ایونت
            // از quantity محاسبه‌شده استفاده می‌کنیم تا با RemoveCartItemAction سازگار باشد
            if ($existingItem) {
                $previousQuantity = (float) $existingItem->getOriginal('quantity');
                $newQuantity = (float) $calculatedPhys['quantity'];
                $quantityAdded = $newQuantity - $previousQuantity;
            } else {
                $quantityAdded = (float) $calculatedPhys['quantity'];
            }

            CartItemChanged::dispatch(
                $cart,
                $variant,
                CartEvent::ACTION_ADD,
                $quantityAdded,
                (float) $priceData['unit_price']
            );

            return $cart->load([
                'items.productVariant',
                'items.factory',
                'items.shippingMethod',
                'customerType',
                'badge',
            ]);
        });
    }

    protected function calculatePhysicalAttributes(array $quantities, Collection $orderUnitsMap, ProductVariant $variant): array
    {
        $totalWeight = 0.0;
        $totalQuantity = 0.0;
        $primaryUnitName = null;

        foreach ($quantities as $unitId => $qty) {
            $qty = (float) $qty;
            $totalQuantity += $qty;

            $unit = $orderUnitsMap->get((int) $unitId);

            if (! $primaryUnitName && $unit) {
                $primaryUnitName = $unit->name;
            }

            if ($unit) {
                $totalWeight += $qty * (float) $unit->amount;
            } else {
                $variantWeight = (float) ($variant->weight ?? 0);
                if ($variantWeight <= 0) {
                    $variantWeight = 1;
                }
                $totalWeight += $qty * $variantWeight;
            }
        }

        return [
            'weight' => $totalWeight,
            'quantity' => $totalQuantity,
            'unit_name' => $primaryUnitName ?? 'عدد',
        ];
    }

    protected function calculateItemPrice(Cart $cart, ProductVariant $variant, array $data, float $totalWeight): array
    {
        $user = $cart->user;
        $roleCode = $user ? $this->roleResolver->resolve($user) : 'employer_without_consultant';

        $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();
        $activeFilter = PriceFilterCategory::where('code', $roleCode)->first();
        $filterIds = $activeFilter ? [$activeFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();

        // امن‌سازی فراخوانی کارخانه و لوکیشن
        $factory = Factory::find($data['factory_id']);
        $location = FactoryLocation::find($data['location_id']);

        if (! $factory || ! $location) {
            throw ValidationException::withMessages([
                'factory_id' => 'اطلاعات کارخانه یا محل تحویل نامعتبر است.',
            ]);
        }

        $pricing = $this->priceService->calculatePrice(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterIds,
            withVAT: true,
            filterCategoryCode: $roleCode
        );

        $lineTotal = $totalWeight * $pricing->finalPrice;
        $lineVat = $totalWeight * $pricing->vatAmount;

        return [
            'unit_price' => $pricing->finalPrice,
            'vat_amount' => $lineVat,
            'total_with_vat' => $lineTotal + $lineVat,
        ];
    }

    protected function getOrderUnitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, fn () => OrderUnit::all()->keyBy(OrderUnit::ID));
    }

    protected function mergeQuantities(array $current, array $new): array
    {
        $merged = $current;
        foreach ($new as $unitId => $qty) {
            $key = (string) $unitId;

            $currentVal = 0;
            if (isset($merged[$key])) {
                $currentVal = $merged[$key];
            } elseif (isset($merged[(int) $unitId])) {
                $currentVal = $merged[(int) $unitId];
                unset($merged[(int) $unitId]);
            }

            $merged[$key] = (float) $currentVal + (float) $qty;
        }

        return $merged;
    }

    protected function calculateTotalVariantQuantities(Cart $cart, int $variantId, ?CartItem $existingItem, array $targetItemQuantities): array
    {
        $totals = [];

        foreach ($cart->items as $item) {
            if ($item->product_variant_id !== $variantId) {
                continue;
            }
            if ($existingItem && $item->id === $existingItem->id) {
                continue;
            }

            $q = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
            if (! is_array($q)) {
                continue;
            }

            foreach ($q as $u => $val) {
                $k = (string) $u;
                $totals[$k] = ($totals[$k] ?? 0) + $val;
            }
        }

        foreach ($targetItemQuantities as $u => $q) {
            $k = (string) $u;
            $totals[$k] = ($totals[$k] ?? 0) + $q;
        }

        return $totals;
    }

    protected function convertQuantitiesToTargetUnitValue(array $itemQuantities, ?OrderUnit $targetLimitOrderUnit, Collection $orderUnitsMap): ?float
    {
        if (! $targetLimitOrderUnit || $targetLimitOrderUnit->amount == 0) {
            return null;
        }

        $totalValueInBase = 0;
        foreach ($itemQuantities as $unitId => $count) {
            $currentOrderUnit = $orderUnitsMap->get((int) $unitId);
            if ($currentOrderUnit && $currentOrderUnit->amount > 0) {
                $totalValueInBase += (float) $count * $currentOrderUnit->amount;
            }
        }

        return $totalValueInBase / $targetLimitOrderUnit->amount;
    }

    protected function validateOrderUnitCounts(ProductVariant $variant, array $totalQuantities): void
    {
        if (! $variant->relationLoaded('product')) {
            $variant->load('product.orderUnits');
        }

        $unitMap = $variant->product->orderUnits->keyBy('id');

        foreach ($totalQuantities as $unitId => $qty) {
            $unit = $unitMap->get((int) $unitId);
            if (! $unit) {
                continue;
            }

            $maxCount = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            if ($qty > $maxCount) {
                throw ValidationException::withMessages(['quantities.'.$unitId => [
                    __('cart.limits.max_unit_count', [
                        'qty' => $qty,
                        'unit' => $unit->name,
                        'max' => $maxCount,
                    ]),
                ]]);
            }
        }
    }

    protected function checkVariantAggregateLimits(ProductVariant $variant, array $totalQuantities, Collection $orderUnitsMap): void
    {
        $minValue = $variant->min_order_value;
        $maxValue = $variant->max_order_value;
        $limitUnitId = $variant->limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) {
            return;
        }

        if (! $variant->relationLoaded('limitOrderUnit')) {
            $variant->load('limitOrderUnit');
        }
        $limitUnit = $variant->limitOrderUnit;
        if (! $limitUnit) {
            return;
        }

        $totalValue = $this->convertQuantitiesToTargetUnitValue($totalQuantities, $limitUnit, $orderUnitsMap);
        if ($totalValue === null) {
            return;
        }

        $unitName = $limitUnit->name;
        $valRounded = round($totalValue, 2);

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['quantities' => [
                __('cart.limits.min_variant_value', [
                    'name' => $variant->name,
                    'min' => $minValue,
                    'unit' => $unitName,
                    'current' => $valRounded,
                ]),
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['quantities' => [
                __('cart.limits.max_variant_value', [
                    'name' => $variant->name,
                    'max' => $maxValue,
                    'unit' => $unitName,
                    'current' => $valRounded,
                ]),
            ]]);
        }
    }

    protected function checkProductLineAggregateLimits(Cart $cart, Product $product, Collection $orderUnitsMap): void
    {
        $minValue = $product->aggregate_min_order_value;
        $maxValue = $product->aggregate_max_order_value;
        $limitUnitId = $product->aggregate_limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) {
            return;
        }

        if (! $product->relationLoaded('aggregateLimitOrderUnit')) {
            $product->load('aggregateLimitOrderUnit');
        }
        $limitUnit = $product->aggregateLimitOrderUnit;
        if (! $limitUnit || $limitUnit->amount == 0) {
            return;
        }

        $totalValueInBase = 0;
        foreach ($cart->items as $item) {
            if ($item->productVariant->product_id === $product->id) {
                $q = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
                if (! is_array($q)) {
                    continue;
                }

                foreach ($q as $unitId => $count) {
                    $unit = $orderUnitsMap->get((int) $unitId);
                    if ($unit && $unit->amount > 0) {
                        $totalValueInBase += (float) $count * $unit->amount;
                    }
                }
            }
        }

        $totalValue = $totalValueInBase / $limitUnit->amount;
        $valRounded = round($totalValue, 2);
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['product_line' => [
                __('cart.limits.min_product_value', [
                    'name' => $product->name,
                    'min' => $minValue,
                    'unit' => $unitName,
                    'current' => $valRounded,
                ]),
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['product_line' => [
                __('cart.limits.max_product_value', [
                    'name' => $product->name,
                    'max' => $maxValue,
                    'unit' => $unitName,
                    'current' => $valRounded,
                ]),
            ]]);
        }
    }
}

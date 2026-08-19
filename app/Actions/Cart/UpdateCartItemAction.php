<?php

namespace App\Actions\Cart;

use App\Exceptions\BusinessException;
use App\Models\Cart;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCartItemAction
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected PriceCalculationService $priceService,
        protected PricingRoleResolver $roleResolver
    ) {}

    public function execute(CartItem $cartItem, array $data): CartItem
    {

        $cartItem->loadMissing(['productVariant.product.orderUnits', 'productVariant.limitOrderUnit', 'cart.user', 'cart.customerType', 'cart.badge', 'cart.priceFilterCategories', 'cart.items']);
        $variant = $cartItem->productVariant;
        $cart = $cartItem->cart;

        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cartItem, $variant, $cart, $data, $orderUnitsMap) {

            // قفلِ ردیفِ سبد — هم‌سو با AddToCartAction — تا افزودن/به‌روزرسانیِ هم‌زمان
            // روی یک سبد، محدودیت‌های تجمیعی (aggregate) را روی snapshotِ کهنه محاسبه نکنند.
            Cart::where('id', $cart->id)->lockForUpdate()->first();

            $newQuantities = $cartItem->quantities;
            if (is_string($newQuantities)) {
                $newQuantities = json_decode($newQuantities, true);
            }

            if (isset($data['quantities'])) {
                $newQuantities = $data['quantities'];

                // مبدأِ خودِ ردیف پاس داده می‌شود: موجودی به‌ازای کارخانه/انبار
                // نگه داشته می‌شود و چکِ سراسری برای هیچ مبدأ خاصی درست نیست.
                $stockOk = $this->inventoryService->checkStock(
                    $variant,
                    $newQuantities,
                    (int) $cartItem->{CartItem::FACTORY_ID} ?: null,
                    (int) $cartItem->{CartItem::FACTORY_LOCATION_ID} ?: null,
                );

                if (! $stockOk) {
                    throw ValidationException::withMessages([
                        'quantities' => __('cart.limits.insufficient_stock_simple'),
                    ]);
                }

                $this->validateOrderUnitCounts($variant, $newQuantities, $cart, $cartItem);

                $totalVariantQuantities = $this->calculateTotalVariantQuantities($cart, $variant->id, $cartItem, $newQuantities);
                $this->checkVariantAggregateLimits($variant, $totalVariantQuantities, $orderUnitsMap);

                $cartItem->quantities = $newQuantities;
            }

            if (isset($data['shipping_method_id'])) {
                $cartItem->shipping_method_id = $data['shipping_method_id'];
            }

            // The request field is `location_id` (UpdateCartItemRequest). Apply it and
            // keep `factory_id` consistent by deriving it from the chosen location —
            // a location belongs to exactly one factory, so this also handles switching
            // origin factory. (Previously the action read a non-existent
            // `factory_location_id` key, so the required location change was silently
            // dropped and the line re-priced against the old origin.)
            if (isset($data['location_id'])) {
                $location = FactoryLocation::find($data['location_id']);
                if ($location) {
                    // یک سفارش = یک مبدأ (تصمیم مدیریت، بند ۳). ویرایشِ ردیف
                    // همان‌قدر می‌تواند سبد را چندمبدأیی کند که افزودنِ تازه —
                    // پس همان گارد اینجا هم لازم است.
                    $this->assertSingleOrigin($cartItem, (int) $location->{FactoryLocation::ID});

                    $cartItem->factory_location_id = $location->{FactoryLocation::ID};
                    $cartItem->factory_id = $location->{FactoryLocation::FACTORY_ID};
                }
            }

            if (array_key_exists('address_id', $data)) {
                $this->assertSingleDestination(
                    $cartItem,
                    $data['address_id'] ? (int) $data['address_id'] : null
                );

                $cartItem->address_id = $data['address_id'];
            }

            $calculatedPhys = $this->calculatePhysicalAttributes($newQuantities, $orderUnitsMap, $variant);

            $priceData = $this->calculateItemPrice(
                $cart,
                $variant,
                $cartItem->factory_id,
                $cartItem->factory_location_id,
                $calculatedPhys['weight']
            );

            // ۴. بروزرسانی فیلدهای دیتابیس
            $cartItem->weight = $calculatedPhys['weight'];
            $cartItem->quantity = $calculatedPhys['quantity'];
            $cartItem->unit_name = $calculatedPhys['unit_name'];

            $cartItem->price = $priceData['unit_price'];
            $cartItem->final_price = $priceData['unit_price'];
            $cartItem->vat_amount = $priceData['vat_amount'];
            $cartItem->total_with_vat = $priceData['total_with_vat'];

            $cartItem->save();

            // ۵. بررسی لیمیت کل گروه کالا بعد از ذخیره
            $freshCart = $cart->fresh(['items.productVariant.product.aggregateLimitOrderUnit']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            return $cartItem;
        });
    }

    // =========================================================================
    //  متدهای محاسباتی مالی و فیزیکی
    // =========================================================================

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

    protected function calculateItemPrice(Cart $cart, ProductVariant $variant, int $factoryId, int $locationId, float $totalWeight): array
    {
        $user = $cart->user;
        $roleCode = $user ? $this->roleResolver->resolve($user) : 'employer_without_elite_yar';

        $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();
        $activeFilter = PriceFilterCategory::where('code', $roleCode)->first();
        $filterIds = $activeFilter ? [$activeFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();

        $factory = Factory::find($factoryId);
        $location = FactoryLocation::find($locationId);

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

    // =========================================================================
    //  متدهای کمکی و بررسی محدودیت‌ها
    // =========================================================================

    protected function getOrderUnitsMap(): Collection
    {
        return OrderUnit::allCached();
    }

    protected function calculateTotalVariantQuantities(Cart $cart, int $variantId, CartItem $currentItem, array $newQuantities): array
    {
        $totals = [];

        foreach ($cart->items as $item) {
            if ($item->product_variant_id !== $variantId) {
                continue;
            }
            if ($item->id === $currentItem->id) {
                continue;
            }

            $q = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
            if (! is_array($q)) {
                continue;
            }

            foreach ($q as $u => $val) {
                $k = (string) $u;
                $totals[$k] = ($totals[$k] ?? 0) + (float) $val;
            }
        }

        foreach ($newQuantities as $u => $q) {
            $k = (string) $u;
            $totals[$k] = ($totals[$k] ?? 0) + (float) $q;
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

    protected function validateOrderUnitCounts(ProductVariant $variant, array $newQuantities, Cart $cart, CartItem $currentItem): void
    {
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($newQuantities as $unitId => $qty) {
            $unitId = (int) $unitId;
            $qty = (float) $qty;

            $unit = $unitMap->get($unitId);
            if (! $unit) {
                throw ValidationException::withMessages(['quantities.'.$unitId => [__('cart.limits.invalid_unit')]]);
            }

            if ($qty < 0) {
                throw ValidationException::withMessages(['quantities.'.$unitId => [__('cart.limits.negative_quantity')]]);
            }

            $maxCount = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            $othersInCart = $cart->items
                ->where('id', '!=', $currentItem->id)
                ->where('product_variant_id', $variant->id)
                ->sum(fn ($item) => $item->quantities[(string) $unitId] ?? ($item->quantities[(int) $unitId] ?? 0));

            $totalRequested = $othersInCart + $qty;

            if ($totalRequested > $maxCount) {
                throw ValidationException::withMessages(['quantities.'.$unitId => [
                    __('cart.limits.max_unit_count', [
                        'qty' => number_format($totalRequested),
                        'unit' => $unit->name,
                        'max' => number_format($maxCount),
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
                    'min' => number_format($minValue),
                    'unit' => $unitName,
                    'current' => number_format($valRounded),
                ]),
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['quantities' => [
                __('cart.limits.max_variant_value', [
                    'name' => $variant->name,
                    'max' => number_format($maxValue),
                    'unit' => $unitName,
                    'current' => number_format($valRounded),
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
                    'min' => number_format($minValue),
                    'unit' => $unitName,
                    'current' => number_format($valRounded),
                ]),
            ]]);
        }
        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['product_line' => [
                __('cart.limits.max_product_value', [
                    'name' => $product->name,
                    'max' => number_format($maxValue),
                    'unit' => $unitName,
                    'current' => number_format($valRounded),
                ]),
            ]]);
        }
    }

    /**
     * مبدأ تازه نباید با **بقیه‌ی** ردیف‌های سبد تضاد داشته باشد.
     *
     * خودِ همین ردیف از مقایسه کنار گذاشته می‌شود، وگرنه سبدِ تک‌ردیفی هرگز
     * نمی‌توانست مبدأ عوض کند — که کارِ کاملاً معتبری است.
     */
    private function assertSingleOrigin(CartItem $cartItem, int $factoryLocationId): void
    {
        $conflict = CartItem::where(CartItem::CART_ID, $cartItem->{CartItem::CART_ID})
            ->whereKeyNot($cartItem->getKey())
            ->whereNotNull(CartItem::FACTORY_LOCATION_ID)
            ->where(CartItem::FACTORY_LOCATION_ID, '!=', $factoryLocationId)
            ->exists();

        if ($conflict) {
            throw new BusinessException(__('cart.limits.single_origin_only'), 422);
        }
    }

    /** همان قاعده برای مقصد. `null` یعنی «آدرسِ اصلیِ سفارش» و تضادی نمی‌سازد. */
    private function assertSingleDestination(CartItem $cartItem, ?int $addressId): void
    {
        if ($addressId === null) {
            return;
        }

        $conflict = CartItem::where(CartItem::CART_ID, $cartItem->{CartItem::CART_ID})
            ->whereKeyNot($cartItem->getKey())
            ->whereNotNull(CartItem::ADDRESS_ID)
            ->where(CartItem::ADDRESS_ID, '!=', $addressId)
            ->exists();

        if ($conflict) {
            throw new BusinessException(__('cart.limits.single_destination_only'), 422);
        }
    }
}

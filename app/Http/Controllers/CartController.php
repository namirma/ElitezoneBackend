<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Requests\UpdateCartFiltersRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Requests\UpdateCustomerTypeRequest;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\CartResource;
use App\Http\Resources\OfferResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Offer;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\PriceSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class CartController extends Controller
{
    private ?Collection $orderUnitsMapCache = null;

    public function __construct(private readonly PriceCalculationService $priceService) {}

    private function activeCode(): string
    {
        if (app()->bound('active_price_filter_category_code')) {
            return app('active_price_filter_category_code');
        }
        if (Auth::check()) {
            return app(PricingRoleResolver::class)->resolve(Auth::user());
        }
        return 'employer_without_consultant';
    }

    private function getOrderUnitsMap(): Collection
    {
        if ($this->orderUnitsMapCache === null) {
            $this->orderUnitsMapCache = OrderUnit::all()->keyBy(OrderUnit::ID);
        }
        return $this->orderUnitsMapCache;
    }

    private function convertQuantitiesToTargetUnitValue(array $itemQuantities, ?OrderUnit $targetLimitOrderUnit, Collection $orderUnitsMap): ?float
    {
        if (!$targetLimitOrderUnit || $targetLimitOrderUnit->{OrderUnit::AMOUNT} == 0) {
            return null;
        }
        $totalValueInBase = 0;
        foreach ($itemQuantities as $unitId => $count) {
            $currentOrderUnit = $orderUnitsMap->get((int)$unitId);
            if ($currentOrderUnit && $currentOrderUnit->{OrderUnit::AMOUNT} > 0) {
                $totalValueInBase += (float)$count * $currentOrderUnit->{OrderUnit::AMOUNT};
            }
        }
        return $totalValueInBase / $targetLimitOrderUnit->{OrderUnit::AMOUNT};
    }

    private function validateOrderUnitCounts(ProductVariant $variant, array $quantitiesForOperation, Cart $cart, ?CartItem $itemBeingManipulated = null): void
    {
        if (!$variant->relationLoaded('product') || !$variant->product->relationLoaded('orderUnits')) {
            $variant->loadMissing('product.orderUnits');
        }
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($quantitiesForOperation as $unitIdToString => $quantityCountInOperation) {
            $unitId = (int)$unitIdToString;
            $quantityCountInOperation = (float)$quantityCountInOperation;
            $unit = $unitMap->get($unitId);

            if (!$unit) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["واحد سفارش با شناسه {$unitId} برای این محصول نامعتبر است."]]);
            }
            if ($quantityCountInOperation < 0) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["تعداد واحد سفارش \"{$unit->{OrderUnit::NAME}}\" نمی‌تواند منفی باشد."]]);
            }

            $maxCountPerUnit = $unit->pivot->max_quantity ?? PHP_INT_MAX;
            $currentTotalInCartForThisUnit = $cart->items
                ->when($itemBeingManipulated, fn(Collection $items) => $items->where(CartItem::ID, '!=', $itemBeingManipulated->id))
                ->where(CartItem::PRODUCT_VARIANT_ID, $variant->id)
                ->sum(fn($cartItem) => $cartItem->{CartItem::QUANTITIES}[(string)$unitId] ?? ($cartItem->{CartItem::QUANTITIES}[(int)$unitId] ?? 0));

            if (($currentTotalInCartForThisUnit + $quantityCountInOperation) > $maxCountPerUnit) {
                $message = "حداکثر مجاز برای \"{$unit->{OrderUnit::NAME}}\" برابر {$maxCountPerUnit} است. در سبد هم‌اکنون {$currentTotalInCartForThisUnit} عدد از این واحد برای این واریانت دارید و نمی‌توانید {$quantityCountInOperation} عدد دیگر اضافه کنید.";
                throw ValidationException::withMessages(['quantities.' . $unitId => [$message]]);
            }
        }
    }

    private function validateQuantitiesForUpdate(Cart $cart, CartItem $currentItem, ProductVariant $variant, array $newQuantitiesForThisItem): void
    {
        if (!$variant->relationLoaded('product') || !$variant->product->relationLoaded('orderUnits')) {
            $variant->loadMissing('product.orderUnits');
        }
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');
        foreach ($newQuantitiesForThisItem as $unitIdToString => $newUnitCount) {
            $unitId = (int)$unitIdToString;
            $newUnitCount = (float)$newUnitCount;
            $unit = $unitMap->get($unitId);
            if (!$unit) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["واحد سفارش با شناسه {$unitId} برای این محصول نامعتبر است."]]);
            }
            if ($newUnitCount < 0) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["تعداد واحد سفارش \"{$unit->{OrderUnit::NAME}}\" نمی‌تواند منفی باشد."]]);
            }
            $maxQuantityPerUnit = $unit->pivot->max_quantity ?? PHP_INT_MAX;
            $currentTotalOfThisUnitForOtherItems = $cart->items
                ->where(CartItem::PRODUCT_VARIANT_ID, $variant->id)->where(CartItem::ID, '!=', $currentItem->id)
                ->sum(fn($cartItem) => $cartItem->{CartItem::QUANTITIES}[(string)$unitId] ?? ($cartItem->{CartItem::QUANTITIES}[(int)$unitId] ?? 0));
            if (($currentTotalOfThisUnitForOtherItems + $newUnitCount) > $maxQuantityPerUnit) {
                $message = "حداکثر مجاز برای \"{$unit->{OrderUnit::NAME}}\" برابر {$maxQuantityPerUnit} است. سایر آیتم‌های این محصول در سبد شما {$currentTotalOfThisUnitForOtherItems} عدد از این واحد دارند و با درخواست جدید شما مجموع به " . ($currentTotalOfThisUnitForOtherItems + $newUnitCount) . " می‌رسد.";
                throw ValidationException::withMessages(['quantities.' . $unitId => [$message]]);
            }
        }
    }

    private function checkVariantAggregateLimits(ProductVariant $variant, array $finalQuantitiesForThisInstance, Collection $orderUnitsMap): void
    {
        $minValue = $variant->{ProductVariant::MIN_ORDER_VALUE};
        $maxValue = $variant->{ProductVariant::MAX_ORDER_VALUE};
        $limitUnitId = $variant->{ProductVariant::LIMIT_ORDER_UNIT_ID};
        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;
        if (!$variant->relationLoaded('limitOrderUnit')) $variant->load('limitOrderUnit');
        $limitOrderUnit = $variant->limitOrderUnit;
        if (!$limitOrderUnit) {
            throw ValidationException::withMessages(['variant_config' => ["واحد محدودیت (شناسه: {$limitUnitId}) تعریف شده برای واریانت \"{$variant->{ProductVariant::NAME}}\" نامعتبر است."]]);
        }
        $totalValueInLimitUnit = $this->convertQuantitiesToTargetUnitValue($finalQuantitiesForThisInstance, $limitOrderUnit, $orderUnitsMap);
        if ($totalValueInLimitUnit === null) return;
        $variantName = $variant->{ProductVariant::NAME} ?? 'این واریانت';
        $limitUnitName = $limitOrderUnit->{OrderUnit::NAME} ?? 'واحد';
        if ($minValue !== null && $totalValueInLimitUnit > 0.0001 && $totalValueInLimitUnit < $minValue) {
            throw ValidationException::withMessages(['quantities' => ["حداقل سفارش برای \"{$variantName}\" باید {$minValue} {$limitUnitName} باشد. مقدار فعلی این آیتم: " . round($totalValueInLimitUnit, 2) . " {$limitUnitName}"]]);
        }
        if ($maxValue !== null && $totalValueInLimitUnit > $maxValue) {
            throw ValidationException::withMessages(['quantities' => ["حداکثر سفارش برای \"{$variantName}\" برابر {$maxValue} {$limitUnitName} است. مقدار فعلی این آیتم: " . round($totalValueInLimitUnit, 2) . " {$limitUnitName}"]]);
        }
    }

    private function checkProductLineAggregateLimits(Cart $cart, Product $product, Collection $orderUnitsMap): void
    {
        $minValue = $product->{Product::AGGREGATE_MIN_ORDER_VALUE};
        $maxValue = $product->{Product::AGGREGATE_MAX_ORDER_VALUE};
        $limitUnitId = $product->{Product::AGGREGATE_LIMIT_ORDER_UNIT_ID};
        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;
        if (!$product->relationLoaded('aggregateLimitOrderUnit')) $product->load('aggregateLimitOrderUnit');
        $limitOrderUnit = $product->aggregateLimitOrderUnit;
        if (!$limitOrderUnit) {
            throw ValidationException::withMessages(['product_config' => ["واحد محدودیت (شناسه: {$limitUnitId}) تعریف شده برای محصول \"{$product->{Product::NAME}}\" نامعتبر است."]]);
        }
        $totalProductLineValueInBase = 0;
        foreach ($cart->items as $itemInCart) {
            if ($itemInCart->productVariant->{ProductVariant::PRODUCT_ID} === $product->{Product::ID}) {
                foreach ($itemInCart->{CartItem::QUANTITIES} as $currentUnitId => $count) {
                    $currentOrderUnit = $orderUnitsMap->get((int)$currentUnitId);
                    if ($currentOrderUnit && $currentOrderUnit->{OrderUnit::AMOUNT} > 0) {
                        $totalProductLineValueInBase += (float)$count * $currentOrderUnit->{OrderUnit::AMOUNT};
                    }
                }
            }
        }
        if ($limitOrderUnit->{OrderUnit::AMOUNT} == 0) {
            throw new \LogicException("Order unit '{$limitOrderUnit->{OrderUnit::NAME}}' for product line '{$product->{Product::NAME}}' has an amount of zero.");
        }
        $totalValueInLimitUnit = $totalProductLineValueInBase / $limitOrderUnit->{OrderUnit::AMOUNT};
        $totalValueInLimitUnit = round($totalValueInLimitUnit, 5);
        $productName = $product->{Product::NAME} ?? 'این گروه محصول';
        $limitUnitName = $limitOrderUnit->{OrderUnit::NAME} ?? 'واحد';
        if ($minValue !== null && $totalValueInLimitUnit > 0.0001 && $totalValueInLimitUnit < $minValue) {
            throw ValidationException::withMessages(['product_line' => ["حداقل مجموع سفارش برای محصولات \"{$productName}\" باید {$minValue} {$limitUnitName} باشد. مقدار فعلی این محصولات در سبد: " . round($totalValueInLimitUnit, 2) . " {$limitUnitName}"]]);
        }
        if ($maxValue !== null && $totalValueInLimitUnit > $maxValue) {
            throw ValidationException::withMessages(['product_line' => ["حداکثر مجموع سفارش برای محصولات \"{$productName}\" برابر {$maxValue} {$limitUnitName} است. مقدار فعلی این محصولات در سبد: " . round($totalValueInLimitUnit, 2) . " {$limitUnitName}"]]);
        }
    }

    public function getCart(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $this->loadRelations($cart);
        [$items, $subtotalAfterVariantDiscounts, $vatAmount, $totalAppliedVariantDiscount] = $this->calculatePricesAndDiscounts($cart, true);
        $offers = OfferResource::collection($this->collectOffers($cart->items));
        $cartLevelOfferDiscountAmount = $this->calculateCartLevelOfferDiscountAmount($subtotalAfterVariantDiscounts, $offers->collection);
        $shippingCost = 0;
        $totals = $this->formatTotals($subtotalAfterVariantDiscounts, $vatAmount, $cartLevelOfferDiscountAmount, $shippingCost, true, $totalAppliedVariantDiscount);
        $activeCode = $this->activeCode();
        $pfId       = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $ctId       = CustomerType::where(CustomerType::CODE, $activeCode)->value('id');
        return success([
            'cart'    => new CartResource($cart),
            'offers'  => $offers,
            'totals'  => $totals,
            'pricing' => [
                'code' => $activeCode,
                'price_filter_category_id' => $pfId,
                'customer_type_id' => $ctId,
            ],
        ], 'global.received_successfully');
    }

    private function calculatePricesAndDiscounts(Cart $cart, bool $withVat): array
    {
        $subtotalAfterVariantDiscounts = 0.0;
        $totalVatAmount = 0.0;
        $totalAppliedVariantDiscount = 0.0;
        $orderUnitsMap = $this->getOrderUnitsMap();
        $activeCode   = $this->activeCode();
        $pfId         = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds    = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck(PriceFilterCategory::ID)->all();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first() ?: $cart->customerType;

        foreach ($cart->items as $item) {
            // دریافت نتیجه به صورت DTO
            $pricingDetailsDto = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $customerType,
                $cart->badge,
                $filterIds,
                $withVat
            );

            $itemTotalWeight = 0;
            foreach ($item->{CartItem::QUANTITIES} as $unitId => $qty) {
                $unit = $orderUnitsMap->get((int)$unitId);
                if ($unit) $itemTotalWeight += (float)$qty * $unit->{OrderUnit::AMOUNT};
            }

            // اصلاح: استفاده از دسترسی Object (->) به جای Array ([])
            // فرض می‌کنیم نام پراپرتی‌ها در DTO به صورت camelCase است
            $variantDiscountAmountFromService = isset($pricingDetailsDto->variantDiscountAmount) ? (float)$pricingDetailsDto->variantDiscountAmount : 0.0;
            $totalAppliedVariantDiscount += $variantDiscountAmountFromService * $itemTotalWeight;

            // اصلاح: دسترسی به finalPrice
            $itemFinalPrice = $itemTotalWeight * (float)$pricingDetailsDto->finalPrice;
            $itemVatAmount = 0.0;
            // اصلاح: دسترسی به vatRate
            if ($withVat && isset($pricingDetailsDto->vatRate) && (float)$pricingDetailsDto->vatRate > 0) {
                $itemVatAmount = $itemFinalPrice * ((float)$pricingDetailsDto->vatRate / 100);
            }

            $item->setAttribute('pricing_context', [
                'code' => $activeCode,
                'price_filter_category_id' => $pfId,
                'customer_type_id' => optional($customerType)->id,
            ]);

            // اصلاح: تبدیل DTO به آرایه برای ذخیره در دیتابیس (JSON)
            $item->setAttribute('unit_price_details', $pricingDetailsDto->toArray());

            $item->fill([
                CartItem::PRICE          => $pricingDetailsDto->finalPrice, // اصلاح
                CartItem::FINAL_PRICE    => $itemFinalPrice,
                CartItem::VAT_AMOUNT     => $itemVatAmount,
                CartItem::TOTAL_WITH_VAT => $itemFinalPrice + $itemVatAmount,
            ]);

            $subtotalAfterVariantDiscounts += $itemFinalPrice;
            $totalVatAmount += $itemVatAmount;
        }

        return [$cart->items, $subtotalAfterVariantDiscounts, $totalVatAmount, $totalAppliedVariantDiscount];
    }

    private function calculateCartLevelOfferDiscountAmount(float $subtotal, Collection $offers): float
    {
        $totalDiscountFromOffers = 0.0;
        foreach ($offers as $offer) {
            $discountForThisOffer = 0.0;
            if ($offer->{Offer::TYPE} === 'percentage') {
                $discountForThisOffer = $subtotal * ($offer->{Offer::VALUE} / 100);
            } elseif ($offer->{Offer::TYPE} === 'fixed_amount') {
                $discountForThisOffer = (float)$offer->{Offer::VALUE};
            }
            $totalDiscountFromOffers += $discountForThisOffer;
        }
        return min($totalDiscountFromOffers, $subtotal > 0 ? $subtotal : 0);
    }

    private function formatTotals($subtotalAfterVariantDiscounts, $vatAmount, $cartLevelOfferDiscount, $shippingCost, $withVat, $totalVariantDiscount): array
    {
        $vatRate = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
        $finalDiscountedSubtotal = $subtotalAfterVariantDiscounts - $cartLevelOfferDiscount;
        $finalDiscountedSubtotal = max(0.0, $finalDiscountedSubtotal);
        $totalPrice = ($withVat ? $finalDiscountedSubtotal + $vatAmount : $finalDiscountedSubtotal) + $shippingCost;
        return [
            'subtotal' => (string)$subtotalAfterVariantDiscounts,
            'total_variant_discount' => (string)$totalVariantDiscount,
            'cart_level_offer_discount' => (string)$cartLevelOfferDiscount,
            'subtotal_after_all_discounts' => (string)$finalDiscountedSubtotal,
            'shipping_cost' => (string)$shippingCost,
            'vat_amount' => (string)$vatAmount,
            'vat_rate' => (string)($withVat ? $vatRate : 0.0),
            'total' => (string)$totalPrice,
            'with_vat' => $withVat,
        ];
    }

    public function addToCart(AddToCartRequest $request): JsonResponse
    {
        $validatedData   = $request->validated();
        $cart            = $this->resolveCart($request);
        $orderUnitsMap   = $this->getOrderUnitsMap();
        $cartItemToReturn = null;

        try {
            $variant  = ProductVariant::with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])
                ->findOrFail($validatedData['variant_id']);
            $factory  = Factory::findOrFail($validatedData['factory_id']);
            $location = FactoryLocation::findOrFail($validatedData['location_id']);
        } catch (ModelNotFoundException $e) {
            $modelClass = $e->getModel();
            if (str_contains($modelClass, 'ProductVariant'))   return error('global.variant_not_found', [], 404);
            if (str_contains($modelClass, 'Factory'))          return error('global.factory_not_found', [], 404);
            if (str_contains($modelClass, 'FactoryLocation'))  return error('global.location_not_found', [], 404);
            return error('global.notFound', [], 404);
        }

        return DB::transaction(function () use ($cart, $variant, $factory, $location, $validatedData, $orderUnitsMap, &$cartItemToReturn) {
            $identifyingAttributes = [
                CartItem::PRODUCT_VARIANT_ID   => $variant->id,
                CartItem::FACTORY_ID           => $factory->id,
                CartItem::FACTORY_LOCATION_ID  => $location->id,
                CartItem::SHIPPING_METHOD_ID   => $validatedData['shipping_method_id'],
            ];

            $existingCartItem = $cart->items()->where($identifyingAttributes)->first();
            $withVat = true;

            $activeCode = $this->activeCode();
            $pf         = PriceFilterCategory::where('code', $activeCode)->first();
            $filterIds  = $pf ? [$pf->id] : [];
            $customerType = CustomerType::where('code', $activeCode)->first() ?: $cart->customerType;
            $badge          = $cart->badge;

            $this->validateOrderUnitCounts($variant, $validatedData['quantities'], $cart, $existingCartItem);

            if ($existingCartItem) {
                $currentQuantities = $existingCartItem->{CartItem::QUANTITIES};
                $quantitiesToAdd   = $validatedData['quantities'];
                $mergedQuantities  = $currentQuantities;

                foreach ($quantitiesToAdd as $unitId => $qtyToAdd) {
                    $mergedQuantities[(string)$unitId] =
                        ($currentQuantities[(string)$unitId] ?? ($currentQuantities[(int)$unitId] ?? 0))
                        + (float) $qtyToAdd;
                }

                $this->checkVariantAggregateLimits($variant, $mergedQuantities, $orderUnitsMap);

                // دریافت نتیجه به صورت DTO
                $pricingDetailsDto = $this->priceService->calculatePrice(
                    $variant,
                    $factory,
                    $location,
                    $customerType,
                    $badge,
                    $filterIds,
                    $withVat
                );

                $totalDetails = $this->priceService->calculateTotalPrice(
                    $mergedQuantities,
                    (float) $pricingDetailsDto->finalPrice, // اصلاح: استفاده از دسترسی آبجکت
                    $withVat
                );

                $existingCartItem->update([
                    CartItem::QUANTITIES     => $mergedQuantities,
                    CartItem::PRICE          => $pricingDetailsDto->finalPrice, // اصلاح
                    CartItem::FINAL_PRICE    => $totalDetails['subtotal'],
                    CartItem::VAT_AMOUNT     => $totalDetails['vat_amount'],
                    CartItem::TOTAL_WITH_VAT => $totalDetails['total_with_vat'],
                    CartItem::WITH_VAT       => $withVat,
                ]);

                $cartItemToReturn = $existingCartItem;
            } else {
                $this->checkVariantAggregateLimits($variant, $validatedData['quantities'], $orderUnitsMap);

                // دریافت نتیجه به صورت DTO
                $pricingDetailsDto = $this->priceService->calculatePrice(
                    $variant,
                    $factory,
                    $location,
                    $customerType,
                    $badge,
                    $filterIds,
                    $withVat
                );

                $totalDetails = $this->priceService->calculateTotalPrice(
                    $validatedData['quantities'],
                    (float) $pricingDetailsDto->finalPrice, // اصلاح
                    $withVat
                );

                $cartItemToReturn = CartItem::create([
                    CartItem::CART_ID             => $cart->id,
                    CartItem::PRODUCT_VARIANT_ID  => $variant->id,
                    CartItem::FACTORY_ID          => $factory->id,
                    CartItem::FACTORY_LOCATION_ID => $location->id,
                    CartItem::SHIPPING_METHOD_ID  => $validatedData['shipping_method_id'],
                    CartItem::QUANTITIES          => $validatedData['quantities'],
                    CartItem::PRICE               => $pricingDetailsDto->finalPrice, // اصلاح
                    CartItem::FINAL_PRICE         => $totalDetails['subtotal'],
                    CartItem::VAT_AMOUNT          => $totalDetails['vat_amount'],
                    CartItem::TOTAL_WITH_VAT      => $totalDetails['total_with_vat'],
                    CartItem::WITH_VAT            => $withVat,
                ]);
            }

            $reloadedCart = $cart->fresh(['items.productVariant.product.aggregateLimitOrderUnit']);
            $this->checkProductLineAggregateLimits($reloadedCart, $variant->product, $orderUnitsMap);

            $cartItemToReturn->load([
                'productVariant.variantAttributes.attributeType',
                'factory',
                'factoryLocation',
                'shippingMethod'
            ]);
            return success([], 'global.received_successfully')
                ->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
        });
    }


    public function updateCartItem(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        $validatedData = $request->validated();
        $cart = $this->resolveCart($request);
        $orderUnitsMap = $this->getOrderUnitsMap();
        if ($item->{CartItem::CART_ID} !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        try {
            $location = FactoryLocation::findOrFail($validatedData['location_id']);
        } catch (ModelNotFoundException $e) {
            return error('global.location_not_found', [], 404);
        }

        $productVariant = $item->productVariant()->with(['product.orderUnits', 'product.aggregateLimitOrderUnit', 'limitOrderUnit'])->first();
        if (!$productVariant) {
            return error('global.variant_not_found', [], 404);
        }

        return DB::transaction(function () use ($cart, $item, $productVariant, $location, $validatedData, $orderUnitsMap) {
            $this->validateQuantitiesForUpdate($cart, $item, $productVariant, $validatedData['quantities']);
            $this->checkVariantAggregateLimits($productVariant, $validatedData['quantities'], $orderUnitsMap);

            $withVat = true;
            $activeCode = $this->activeCode();
            $pf         = PriceFilterCategory::where('code', $activeCode)->first();
            $filterIds  = $pf ? [$pf->id] : [];

            // دریافت نتیجه به صورت DTO
            $pricingDetailsDto = $this->priceService->calculatePrice($productVariant, $item->factory, $location, $cart->customerType, $cart->badge, $filterIds, $withVat);

            $totalDetails = $this->priceService->calculateTotalPrice($validatedData['quantities'], (float)$pricingDetailsDto->finalPrice, $withVat); // اصلاح

            $item->update([
                CartItem::FACTORY_LOCATION_ID => $location->id,
                CartItem::QUANTITIES => $validatedData['quantities'],
                CartItem::PRICE => $pricingDetailsDto->finalPrice, // اصلاح
                CartItem::WITH_VAT => $withVat,
                CartItem::FINAL_PRICE => $totalDetails['subtotal'],
                CartItem::VAT_AMOUNT => $totalDetails['vat_amount'],
                CartItem::TOTAL_WITH_VAT => $totalDetails['total_with_vat']
            ]);
            $reloadedCart = $cart->fresh(['items.productVariant.product']);
            $this->checkProductLineAggregateLimits($reloadedCart, $productVariant->product, $orderUnitsMap);
            return success(new CartItemResource($item->refresh()), 'global.received_successfully');
        });
    }

    public function removeCartItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $orderUnitsMap = $this->getOrderUnitsMap();
        if ($item->{CartItem::CART_ID} !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $productOfRemovedItem = $item->productVariant->product()->with('aggregateLimitOrderUnit')->first();
        $undoData = [
            'variant_id' => $item->{CartItem::PRODUCT_VARIANT_ID},
            'factory_id' => $item->{CartItem::FACTORY_ID},
            'location_id' => $item->{CartItem::FACTORY_LOCATION_ID},
            'shipping_method_id' => $item->{CartItem::SHIPPING_METHOD_ID},
            'quantities' => $item->{CartItem::QUANTITIES},
        ];

        DB::transaction(function () use ($item, $cart, $productOfRemovedItem, $orderUnitsMap) {
            $item->delete();
            $reloadedCart = $cart->fresh(['items.productVariant.product']);
            if ($productOfRemovedItem && $reloadedCart->items()->whereHas('productVariant', fn($q) => $q->where(ProductVariant::PRODUCT_ID, $productOfRemovedItem->id))->exists()) {
                $this->checkProductLineAggregateLimits($reloadedCart, $productOfRemovedItem, $orderUnitsMap);
            }
        });
        return success(['undo_data' => $undoData], 'global.received_successfully');
    }

    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $cart->update($request->validated());
        $this->recalculate($cart);
        return success([], 'global.received_successfully');
    }

    public function updateCartFilters(UpdateCartFiltersRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $cart = $this->resolveCart($request);
        $cart->priceFilterCategories()->sync($validatedData['filter_categories'] ?? []);
        $this->recalculate($cart);
        return success([], 'global.received_successfully');
    }

    protected function resolveCart(Request $request): Cart
    {
        $userId = Auth::id();
        if ($userId) {
            $cart = Cart::firstOrCreate([Cart::USER_ID => $userId]);
        } else {
            $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
            $cart = Cart::firstOrCreate([Cart::SESSION_ID => $sessionId], [Cart::USER_ID => null]);
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (!$cart->{Cart::CUSTOMER_TYPE_ID}) {
            $default = CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();
            $cart->forceFill([Cart::CUSTOMER_TYPE_ID => $default->id])->save();
        }
        $activeCode = $this->activeCode();
        $pf  = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->first();
        $ct  = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        if ($pf) {
            $cart->priceFilterCategories()->sync([$pf->id]);
        }
        if ($ct && $cart->{Cart::CUSTOMER_TYPE_ID} !== $ct->id) {
            $cart->forceFill([Cart::CUSTOMER_TYPE_ID => $ct->id])->save();
        }

        return $cart->loadMissing([
            'customerType',
            'badge',
            'priceFilterCategories',
            'items.productVariant.product.aggregateLimitOrderUnit',
            'items.productVariant.limitOrderUnit',
            'items.productVariant.product.orderUnits',
        ]);
    }


    private function loadRelations(Cart $cart): void
    {
        $cart->loadMissing([
            'items.productVariant.product.images',
            'items.productVariant.variantAttributes.attributeType',
            'items.productVariant.product.orderUnits',
            'items.productVariant.product.aggregateLimitOrderUnit',
            'items.productVariant.limitOrderUnit',
            'items.factory',
            'items.factoryLocation',
            'items.shippingMethod',
            'customerType',
            'badge',
            'priceFilterCategories',
            'items'
        ]);
    }

    private function collectOffers(Collection $items): Collection
    {
        return $items->flatMap(fn(CartItem $item) => $item->productVariant->product->offers()->current()->where(Offer::MINIMUM_QUANTITY, '<=', array_sum($item->{CartItem::QUANTITIES}))->get())->unique(Offer::ID)->values();
    }

    private function recalculate(Cart $cart): void
    {
        $this->loadRelations($cart);
        $orderUnitsMap = $this->getOrderUnitsMap();

        DB::transaction(function () use ($cart, $orderUnitsMap) {
            $this->calculatePricesAndDiscounts($cart, true);
            foreach ($cart->items as $item) {
                if ($item->isDirty()) {
                    $item->save();
                }

                if ($item->relationLoaded('productVariant') && $item->productVariant->relationLoaded('product')) {
                    $this->checkVariantAggregateLimits($item->productVariant, $item->{CartItem::QUANTITIES}, $orderUnitsMap);
                }
            }
            $uniqueProductsInCart = $cart->items->pluck('productVariant.product')->unique(Product::ID)->filter();
            foreach ($uniqueProductsInCart as $product) {
                if ($product) $this->checkProductLineAggregateLimits($cart, $product, $orderUnitsMap);
            }
        });
    }
}

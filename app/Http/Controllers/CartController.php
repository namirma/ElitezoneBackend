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
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    public function getCart(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $this->loadRelations($cart);

        [$items, $subtotal, $vatAmount] = $this->calculatePrices($cart, true);

        $offers = OfferResource::collection($this->collectOffers($items));
        $discountAmount = $this->calculateDiscountAmount($subtotal, $offers->collection);

        $shippingCost = 0;

        $totals = $this->formatTotals($subtotal, $vatAmount, $discountAmount, $shippingCost, true);

        return success([
            'cart'   => new CartResource($cart),
            'offers' => $offers,
            'totals' => $totals,
        ], 'global.received_successfully');
    }

    public function addToCart(AddToCartRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $cart = $this->resolveCart($request);

        try {
            $variant = ProductVariant::with('product.orderUnits')->findOrFail($validatedData['variant_id']);
            $factory = Factory::findOrFail($validatedData['factory_id']);
            $location = FactoryLocation::findOrFail($validatedData['location_id']);
        } catch (ModelNotFoundException $e) {
            $modelClass = $e->getModel();
            if (str_contains($modelClass, 'ProductVariant')) return error('global.variant_not_found', [], 404);
            if (str_contains($modelClass, 'Factory')) return error('global.factory_not_found', [], 404);
            if (str_contains($modelClass, 'FactoryLocation')) return error('global.location_not_found', [], 404);
            return error('global.notFound', [], 404);
        }

        $identifyingAttributes = [
            CartItem::PRODUCT_VARIANT_ID  => $variant->id,
            CartItem::FACTORY_ID          => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID  => $validatedData['shipping_method_id'],
        ];

        $existingCartItem = $cart->items()->where($identifyingAttributes)->first();

        $withVat = true;
        $cartItemToReturn = null;

        if ($existingCartItem) {
            $this->validateQuantities($cart, $variant, $validatedData['quantities']);

            $currentQuantities = $existingCartItem->{CartItem::QUANTITIES};
            $quantitiesToAdd = $validatedData['quantities'];
            $mergedQuantities = $currentQuantities;

            foreach ($quantitiesToAdd as $unitId => $qtyToAdd) {
                $mergedQuantities[$unitId] = ($currentQuantities[$unitId] ?? 0) + $qtyToAdd;
            }

            $pricingDetails = $this->priceService->calculatePrice(
                $variant, $factory, $location, $cart->customerType, $cart->badge,
                $validatedData['filter_categories'] ?? [],
                $withVat
            );
            $totalDetails = $this->priceService->calculateTotalPrice(
                $mergedQuantities,
                $pricingDetails['final_price'],
                $withVat
            );

            $existingCartItem->update([
                CartItem::QUANTITIES       => $mergedQuantities,
                CartItem::PRICE            => $pricingDetails['final_price'],
                CartItem::FINAL_PRICE      => $totalDetails['subtotal'],
                CartItem::VAT_AMOUNT       => $totalDetails['vat_amount'],
                CartItem::TOTAL_WITH_VAT   => $totalDetails['total_with_vat'],
                CartItem::WITH_VAT         => $withVat,
            ]);
            $cartItemToReturn = $existingCartItem->refresh();
        } else {
            $this->validateQuantities($cart, $variant, $validatedData['quantities']);

            $pricingDetails = $this->priceService->calculatePrice(
                $variant, $factory, $location, $cart->customerType, $cart->badge,
                $validatedData['filter_categories'] ?? [],
                $withVat
            );
            $totalDetails = $this->priceService->calculateTotalPrice(
                $validatedData['quantities'],
                $pricingDetails['final_price'],
                $withVat
            );

            $cartItemToReturn = CartItem::create([
                CartItem::CART_ID             => $cart->id,
                CartItem::PRODUCT_VARIANT_ID  => $variant->id,
                CartItem::FACTORY_ID          => $factory->id,
                CartItem::FACTORY_LOCATION_ID => $location->id,
                CartItem::SHIPPING_METHOD_ID  => $validatedData['shipping_method_id'],
                CartItem::QUANTITIES          => $validatedData['quantities'],
                CartItem::PRICE               => $pricingDetails['final_price'],
                CartItem::FINAL_PRICE         => $totalDetails['subtotal'],
                CartItem::VAT_AMOUNT          => $totalDetails['vat_amount'],
                CartItem::TOTAL_WITH_VAT      => $totalDetails['total_with_vat'],
                CartItem::WITH_VAT            => $withVat,
            ]);
        }

        $cartItemToReturn->load([
            'productVariant.variantAttributes.attributeType',
            'factory',
            'factoryLocation',
            'shippingMethod',
        ]);

        return success(new CartItemResource($cartItemToReturn), 'global.received_successfully')
            ->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    public function updateCartItem(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        $validatedData = $request->validated();
        $cart = $this->resolveCart($request);

        if ($item->{CartItem::CART_ID} !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        try {
            $location = FactoryLocation::findOrFail($validatedData['location_id']);
        } catch (ModelNotFoundException $e) {
            return error('global.location_not_found', [], 404);
        }

        $productVariant = $item->productVariant()->with('product.orderUnits')->first();
        if (!$productVariant || !$productVariant->product || !$productVariant->product->orderUnits) {
             return error('global.variant_not_found', [], 404);
        }
        $unitMap = $productVariant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($validatedData['quantities'] as $unitId => $newUnitQuantity) {
            $unit = $unitMap[$unitId] ?? null;
            if (!$unit) {
                 throw ValidationException::withMessages([
                   'quantities.'.$unitId => ["واحد سفارش با شناسه {$unitId} برای این محصول نامعتبر است."],
                ]);
            }

            $maxQuantity = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            $otherItemsQuantity = $cart->items()
                ->where(CartItem::PRODUCT_VARIANT_ID, $item->{CartItem::PRODUCT_VARIANT_ID})
                ->where(CartItem::ID, '!=', $item->id)
                ->get()
                ->sum(fn($otherCartItem) => $otherCartItem->{CartItem::QUANTITIES}[$unitId] ?? 0);

            if (($otherItemsQuantity + $newUnitQuantity) > $maxQuantity) {
                $message = "حداکثر مجاز برای \"{$unit->{OrderUnit::NAME}}\" برابر {$maxQuantity} است. در سبد شما برای این محصول (بدون احتساب این آیتم) {$otherItemsQuantity} عدد وجود دارد و با درخواست جدید شما مجموع به ".($otherItemsQuantity + $newUnitQuantity)." می‌رسد.";
                throw ValidationException::withMessages([
                   'quantities.'.$unitId => [$message],
                ]);
            }
        }

        $withVat = true;
        $filterIds = $validatedData['filter_categories'] ?? [];

        $pricingDetails = $this->priceService->calculatePrice(
            $item->productVariant,
            $item->factory,
            $location,
            $cart->customerType,
            $cart->badge,
            $filterIds,
            $withVat
        );

        $totalDetails = $this->priceService->calculateTotalPrice(
            $validatedData['quantities'],
            $pricingDetails['final_price'],
            $withVat
        );

        $item->update(array_merge($totalDetails, [
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::QUANTITIES          => $validatedData['quantities'],
            CartItem::PRICE               => $pricingDetails['final_price'],
            CartItem::WITH_VAT            => $withVat,
        ]));

        return success(new CartItemResource($item->refresh()), 'global.received_successfully');
    }

    public function removeCartItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolveCart($request);

        if ($item->{CartItem::CART_ID} !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $item->delete();
        return success([], 'global.received_successfully');
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
            $cart = Cart::firstOrCreate(
                [Cart::SESSION_ID => $sessionId],
                [Cart::USER_ID    => null]
            );
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (!$cart->{Cart::CUSTOMER_TYPE_ID}) {
            $defaultCustomerType = CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();
            $cart->update([Cart::CUSTOMER_TYPE_ID => $defaultCustomerType->id]);
        }

        $paramIds = $cart->purchaseParameters()->pluck('purchase_parameter_values.id')->all();
        $priceCategory = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($paramIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();

        if ($priceCategory) {
            $cart->priceFilterCategories()->sync([$priceCategory->id]);
            $customerTypeModel = CustomerType::where(CustomerType::CODE, $priceCategory->code)->first();
            if ($customerTypeModel) {
                $cart->update([Cart::CUSTOMER_TYPE_ID => $customerTypeModel->id]);
            }
        }

        return $cart->load(['customerType', 'badge', 'priceFilterCategories']);
    }

    private function loadRelations(Cart $cart): void
    {
        $cart->load([
            'items.productVariant.product.images',
            'items.productVariant.variantAttributes.attributeType',
            'items.factory',
            'items.factoryLocation',
            'items.shippingMethod',
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);
    }

    private function calculatePrices(Cart $cart, bool $withVat): array
    {
        $subtotal = 0;
        $vatAmount = 0;

        foreach ($cart->items as $item) {
            $pricingDetails = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $cart->customerType,
                $cart->badge,
                $cart->priceFilterCategories->pluck(PriceFilterCategory::ID)->all(),
                $withVat
            );

            $orderDetails = $this->priceService->calculateTotalPrice(
                $item->{CartItem::QUANTITIES},
                $pricingDetails['final_price'],
                $withVat
            );

            $item->fill([
                CartItem::PRICE          => $pricingDetails['final_price'],
                CartItem::FINAL_PRICE    => $orderDetails['subtotal'],
                CartItem::VAT_AMOUNT     => $orderDetails['vat_amount'],
                CartItem::TOTAL_WITH_VAT => $orderDetails['total_with_vat'],
            ]);

            $subtotal += $orderDetails['subtotal'];
            $vatAmount += $orderDetails['vat_amount'];
        }

        return [$cart->items, $subtotal, $vatAmount];
    }

    private function collectOffers($items)
    {
        return $items->flatMap(
            fn(CartItem $item) =>
            $item->productVariant->product
                ->offers()
                ->current()
                ->where(Offer::MINIMUM_QUANTITY, '<=', array_sum($item->{CartItem::QUANTITIES}))
                ->get()
        )->unique(Offer::ID)->values();
    }

    private function calculateDiscountAmount($subtotal, $offers): float
    {
        $price = $subtotal;
        foreach ($offers as $offer) {
            $price -= match ($offer->{Offer::TYPE}) {
                'percentage'   => $price * $offer->{Offer::VALUE} / 100,
                'fixed_amount' => $offer->{Offer::VALUE},
                default        => 0,
            };
        }
        return max(0, $subtotal - $price);
    }

    private function formatTotals($subtotal, $vatAmount, $discountAmount, $shippingCost, $withVat): array
    {
        $vatRate = PriceSetting::getValueAsFloat('vat_rate', 10);
        $discountedSubtotal = $subtotal - $discountAmount;
        $totalPrice = ($withVat ? $discountedSubtotal + $vatAmount : $discountedSubtotal) + $shippingCost;

        return [
            'subtotal'       => (string)$subtotal,
            'discount'       => (string)$discountAmount,
            'shipping_cost'  => (string)$shippingCost,
            'vat_amount'     => (string)$vatAmount,
            'vat_rate'       => (string)($withVat ? $vatRate : 0),
            'total'          => (string)$totalPrice,
            'with_vat'       => $withVat,
        ];
    }

    private function recalculate(Cart $cart): void
    {
        $this->loadRelations($cart);
        $this->calculatePrices($cart, true);
    }

    private function validateQuantities(Cart $cart, ProductVariant $variant, array $quantities): void
    {
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($quantities as $unitId => $quantity) {
            $unit = $unitMap[$unitId] ?? null;

            if (!$unit) {
                 throw ValidationException::withMessages([
                   'quantities.'.$unitId => ["واحد سفارش با شناسه {$unitId} نامعتبر است."],
                ]);
            }

            $maxQuantity = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            $existingQuantityInCartForVariantAndUnit = $cart->items()
                ->where(CartItem::PRODUCT_VARIANT_ID, $variant->id)
                ->get()
                ->sum(fn($cartItem) => $cartItem->{CartItem::QUANTITIES}[$unitId] ?? 0);

            if ($existingQuantityInCartForVariantAndUnit + $quantity > $maxQuantity) {
                $message = "حداکثر مجاز برای \"{$unit->{OrderUnit::NAME}}\" برابر {$maxQuantity} است. در سبد هم‌اکنون {$existingQuantityInCartForVariantAndUnit} عدد از این آیتم دارید و نمی‌توانید {$quantity} عدد دیگر اضافه کنید.";
                throw ValidationException::withMessages([
                   'quantities.'.$unitId => [$message],
                ]);
            }
        }
    }
}

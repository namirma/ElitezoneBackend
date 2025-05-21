<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Requests\UpdateCustomerTypeRequest;
use App\Http\Requests\UpdateCartFiltersRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\OfferResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PriceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Services\PriceCalculationService;

class CartController extends Controller
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    /**
     * GET /api/cart
     */
    public function getCart(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $this->loadRelations($cart);

        [$items, $subtotal, $vatAmount] = $this->calculatePrices($cart, true);

        $offers   = OfferResource::collection($this->collectOffers($items));
        $discount = $this->applyDiscounts($subtotal, $offers);
        $totals   = $this->formatTotals($subtotal, $vatAmount, $discount, true);

        return success([
            'cart'   => new CartResource($cart),
            'offers' => $offers,
            'totals' => $totals,
        ]);
    }

    /**
     * POST /api/cart/items
     */
    public function addToCart(AddToCartRequest $request): JsonResponse
    {
        $cart    = $this->resolveCart($request);
        $variant = ProductVariant::with('product.orderUnits')->findOrFail($request->variant_id);
        $this->validateQuantities($cart, $variant, $request->quantities);

        $cart->priceFilterCategories()->sync($request->filter_categories ?? []);

        $factory  = Factory::findOrFail($request->factory_id);
        $location = FactoryLocation::findOrFail($request->location_id);
        $withVat  = true;

        $pricing = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $cart->customerType,
            $cart->badge,
            $request->filter_categories,
            $withVat
        );

        $total = $this->priceService->calculateTotalPrice(
            $request->quantities,
            $pricing['final_price'],
            $withVat
        );

        $item = CartItem::create([
            'cart_id'             => $cart->id,
            'product_variant_id'  => $variant->id,
            'factory_id'          => $factory->id,
            'factory_location_id' => $location->id,
            'shipping_method_id'  => $request->shipping_method_id,
            'quantities'          => $request->quantities,
            'price'               => $pricing['final_price'],
            'final_price'         => $total['subtotal'],
            'vat_amount'          => $total['vat_amount'],
            'total_with_vat'      => $total['total_with_vat'],
            'with_vat'            => $withVat,
        ]);
        $item->load([
            'productVariant.variantAttributes.attributeType',
            'factory',
            'factoryLocation',
            'shippingMethod',
        ]);

        return success(new CartItemResource($item))
            ->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    /**
     * PUT /api/cart/items/{item}
     */
    public function updateCartItem(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolveCart($request);
        abort_unless($item->cart_id === $cart->id, 404);

        $location  = FactoryLocation::findOrFail($request->location_id);
        $withVat   = true;
        $filterIds = $request->filter_categories ?? [];

        $pricing = $this->priceService->calculatePrice(
            $item->productVariant,
            $item->factory,
            $location,
            $cart->customerType,
            $cart->badge,
            $filterIds,
            $withVat
        );

        $total = $this->priceService->calculateTotalPrice(
            $request->quantities,
            $pricing['final_price'],
            $withVat
        );

        $item->update(array_merge($total, [
            'factory_location_id' => $location->id,
            'quantities'          => $request->quantities,
            'price'               => $pricing['final_price'],
            'with_vat'            => $withVat,
        ]));

        return success(new CartItemResource($item->refresh()));
    }

    /**
     * DELETE /api/cart/items/{item}
     */
    public function removeCartItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolveCart($request);
        abort_unless($item->cart_id === $cart->id, 404);

        $item->delete();
        return success([]);
    }

    /**
     * PATCH /api/cart/customer
     */
    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $cart->update($request->only('customer_type_id', 'badge_id'));
        $this->recalculate($cart);

        return success([]);
    }

    /**
     * PATCH /api/cart/filters
     */
    public function updateCartFilters(UpdateCartFiltersRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $cart->priceFilterCategories()->sync($request->filter_categories ?? []);
        $this->recalculate($cart);

        return success([]);
    }

    /* =================================================================
     | Private Helpers
     |=================================================================*/

    protected function resolveCart(Request $request): Cart
    {
        if (Auth::check()) {
            $cart = Cart::firstOrCreate(['user_id' => Auth::id()]);
        } else {
            $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
            $cart = Cart::firstOrCreate(
                ['session_id' => $sessionId],
                ['user_id'    => null]
            );
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (! $cart->customer_type_id) {
            $defaultCt = CustomerType::where('code', 'employer_without_consultant')->firstOrFail();
            $cart->update(['customer_type_id' => $defaultCt->id]);
        }

        if (! $cart->priceFilterCategories()->count()) {
            $defaultFilter = PriceFilterCategory::where('code', 'employer_without_consultant')->first();
            if ($defaultFilter) {
                $cart->priceFilterCategories()->sync([$defaultFilter->id]);
            }
        }

        $paramIds = $cart->purchaseParameters()->pluck('purchase_parameter_values.id')->all();

        $priceCat = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($paramIds)
            ?? PriceFilterCategory::where('code', 'employer_without_consultant')->first();

        if ($priceCat) {
            $cart->priceFilterCategories()->sync([$priceCat->id]);
            $ctModel = CustomerType::where('code', $priceCat->code)->first();
            if ($ctModel) {
                $cart->update(['customer_type_id' => $ctModel->id]);
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
        $subtotal  = 0;
        $vatAmount = 0;

        foreach ($cart->items as $item) {
            $pd = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $cart->customerType,
                $cart->badge,
                $cart->priceFilterCategories->pluck('id')->all(),
                $withVat
            );

            $od = $this->priceService->calculateTotalPrice(
                $item->quantities,
                $pd['final_price'],
                $withVat
            );

            $item->fill([
                'price'          => $pd['final_price'],
                'final_price'    => $od['subtotal'],
                'vat_amount'     => $od['vat_amount'],
                'total_with_vat' => $od['total_with_vat'],
            ]);

            $subtotal  += $od['subtotal'];
            $vatAmount += $od['vat_amount'];
        }

        return [$cart->items, $subtotal, $vatAmount];
    }

    private function collectOffers($items)
    {
        return $items->flatMap(
            fn($item) =>
            $item->productVariant->product
                ->offers()
                ->active()
                ->current()
                ->where('minimum_quantity', '<=', array_sum($item->quantities))
                ->get()
        )->unique('id')->values();
    }

    private function applyDiscounts($subtotal, $offers): float
    {
        $price = $subtotal;
        foreach ($offers as $offer) {
            $price -= match ($offer->type) {
                'percentage'   => $price * $offer->value / 100,
                'fixed_amount' => $offer->value,
                default        => 0,
            };
        }
        return max(0, $subtotal - $price);
    }

    private function formatTotals($subtotal, $vatAmount, $discounted, $withVat): array
    {
        $vatRate    = PriceSetting::getValueAsFloat('vat_rate', 10);
        $totalPrice = $withVat ? $discounted + $vatAmount : $discounted;

        return [
            'subtotal'   => (string)$subtotal,
            'discount'   => (string)($subtotal - $discounted),
            'vat_amount' => (string)$vatAmount,
            'vat_rate'   => (string)($withVat ? $vatRate : 0),
            'total'      => (string)$totalPrice,
            'with_vat'   => $withVat,
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
        foreach ($quantities as $unitId => $qty) {
            $unit     = $unitMap[$unitId] ?? null;
            $max      = $unit?->pivot->max_quantity ?? 0;
            $existing = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->get()
                ->sum(fn($i) => $i->quantities[$unitId] ?? 0);

            if ($existing + $qty > $max) {
                abort(422, "حداکثر مجاز برای \"{$unit->name}\" برابر {$max} است. در سبد هم‌اکنون {$existing} دارید.");
            }
        }
    }
}

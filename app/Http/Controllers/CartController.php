<?php

namespace App\Http\Controllers;

use App\Http\Requests\{
    AddToCartRequest,
    UpdateCartItemRequest,
    UpdateCustomerTypeRequest,
    UpdateCartFiltersRequest
};
use App\Http\Resources\{
    CartResource,
    CartItemResource,
    OfferResource
};
use App\Models\{
    Cart,
    CartItem,
    Factory,
    FactoryLocation,
    ProductVariant,
    PriceSetting,
    CustomerType
};
use App\Services\PriceCalculationService;
use Illuminate\Http\{
    JsonResponse,
    Request
};
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function __construct(protected PriceCalculationService $priceService) {}

    /**
     * نمایش سبد خرید
     */
    public function getCart(Request $request): JsonResponse
    {
        $cart    = $this->resolveCart($request);
        $this->loadCartRelations($cart);

        $withVat = true;
        [$items, $subtotal, $vatAmount] = $this->calculatePrices($cart, $withVat);

        $offerModels = $this->collectOffers($items);

        $offers = OfferResource::collection($offerModels);

        $discount = $this->applyDiscounts($subtotal, $offerModels);
        $totals   = $this->formatTotals($subtotal, $vatAmount, $discount, $withVat);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'cart'   => new CartResource($cart),
                'offers' => $offers,
                'totals' => $totals,
            ],
        ]);
    }

    public function addToCart(AddToCartRequest $request): JsonResponse
    {
        $cart     = $this->resolveCart($request);
        $variant  = ProductVariant::with('product.orderUnits')->findOrFail($request->variant_id);
        $this->validateQuantities($cart, $variant, $request->quantities);

        $cart->priceFilterCategories()->sync($request->filter_categories ?? []);
        $factory  = Factory::findOrFail($request->factory_id);
        $location = FactoryLocation::findOrFail($request->location_id);

        // همیشه با مالیات محاسبه می‌شود
        $withVat = true;
        $pricing = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $cart->customerType,
            $cart->badge,
            $request->filter_categories,
            $withVat
        );
        $total   = $this->priceService->calculateTotalPrice(
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

        return response()->json([
            'status'  => 'success',
            'message' => 'محصول با موفقیت به سبد خرید اضافه شد',
            'data'    => new CartItemResource($item),
        ])->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }


    public function updateCartItem(UpdateCartItemRequest $request, $id): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $item = CartItem::where('id', $id)->where('cart_id', $cart->id)->firstOrFail();

        $location = FactoryLocation::findOrFail($request->location_id);
        $withVat  = true;
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
        $total   = $this->priceService->calculateTotalPrice($request->quantities, $pricing['final_price'], $withVat);

        $item->update(array_merge($total, [
            'factory_location_id' => $location->id,
            'quantities'          => $request->quantities,
            'price'               => $pricing['final_price'],
            'with_vat'            => $withVat,
        ]));

        return response()->json([
            'status'  => 'success',
            'message' => 'آیتم با موفقیت به‌روزرسانی شد',
            'data'    => new CartItemResource($item->refresh()),
        ]);
    }


    public function removeCartItem(Request $request, CartItem $item): JsonResponse
    {
        $this->resolveCart($request);
        $item->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'آیتم حذف شد',
        ]);
    }


    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $cart->update($request->only('customer_type_id', 'badge_id'));
        $this->recalculate($cart);

        return response()->json([
            'status'  => 'success',
            'message' => 'نوع مشتری به‌روزرسانی شد',
        ]);
    }


    public function updateCartFilters(UpdateCartFiltersRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $cart->priceFilterCategories()->sync($request->filter_categories ?? []);
        $this->recalculate($cart);

        return response()->json([
            'status'  => 'success',
            'message' => 'فیلترها به‌روزرسانی شدند',
        ]);
    }

    private function resolveCart(Request $request): Cart
    {
        if (Auth::check()) {
            $cart = Cart::firstOrCreate(['user_id' => Auth::id()]);
        } else {
            $sessionId = $request->cookie('cart_session_id', Str::uuid());
            $cart      = Cart::firstOrCreate(['session_id' => $sessionId]);
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (! $cart->customer_type_id) {
            $default = CustomerType::where('code', 'customer_without_consultant')->first();
            $cart->update(['customer_type_id' => $default->id]);
        }

        return $cart;
    }

    private function loadCartRelations(Cart $cart): void
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
            $pd        = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $cart->customerType,
                $cart->badge,
                $cart->priceFilterCategories->pluck('id')->all(),
                $withVat
            );
            $od        = $this->priceService->calculateTotalPrice($item->quantities, $pd['final_price'], $withVat);

            $item->fill([
                'price'         => $pd['final_price'],
                'final_price'   => $od['subtotal'],
                'vat_amount'    => $od['vat_amount'],
                'total_with_vat' => $od['total_with_vat'],
            ]);

            $subtotal  += $od['subtotal'];
            $vatAmount += $od['vat_amount'];
        }

        return [$cart->items, $subtotal, $vatAmount];
    }

    private function collectOffers($items): Collection
    {
        return $items
            ->flatMap(
                fn($item) =>
                $item->productVariant->product
                    ->offers()
                    ->active()
                    ->current()
                    ->where('minimum_quantity', '<=', array_sum($item->quantities))
                    ->get()
            )
            ->unique('id')
            ->values();
    }

    private function applyDiscounts($subtotal, $offers)
    {
        $price = $subtotal;
        foreach ($offers as $offer) {
            if ($offer->type === 'percentage') {
                $price -= $price * $offer->value / 100;
            } elseif ($offer->type === 'fixed_amount') {
                $price -= $offer->value;
            }
        }
        return max(0, $price);
    }

    private function formatTotals($subtotal, $vatAmount, $discounted, $withVat): array
    {
        return [
            'subtotal'   => $subtotal,
            'discount'   => $subtotal - $discounted,
            'vat_amount' => $vatAmount,
            'vat_rate'   => $withVat ? PriceSetting::getValueAsFloat('vat_rate', 10) : 0,
            'total'      => $withVat ? $discounted + $vatAmount : $discounted,
            'with_vat'   => $withVat,
        ];
    }

    private function recalculate(Cart $cart): void
    {
        $this->loadCartRelations($cart);
        $this->calculatePrices($cart, true);
    }

    private function validateQuantities(Cart $cart, ProductVariant $variant, array $quantities): void
    {
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');
        foreach ($quantities as $unitId => $qty) {
            $unit     = $unitMap[$unitId] ?? null;
            $max      = $unit->pivot->max_quantity ?? 0;
            $existing = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->get()
                ->sum(fn(CartItem $i) => $i->quantities[$unitId] ?? 0);

            if ($existing + $qty > $max) {
                abort(422, "حداکثر مجاز برای \"{$unit->name}\" برابر {$max} است. در سبد هم‌اکنون {$existing} دارید.");
            }
        }
    }
}

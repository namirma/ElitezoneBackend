<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\SyncCartFiltersAction;
use App\Actions\Cart\UpdateCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartFiltersRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Requests\Cart\UpdateCustomerTypeRequest;
use App\Models\CartItem;
use App\Services\Cart\CartResolver;
use App\Services\Cart\CartResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin orchestrator over the Cart Actions:
 *   resolve cart → run action → build response.
 *
 * Cart resolution + context sync lives in CartResolver.
 * Pricing + response formatting lives in CartResponseBuilder.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartResolver        $resolver,
        private readonly CartResponseBuilder $responder,
    ) {}

    /** GET — current cart with computed totals */
    public function getCart(Request $request): JsonResponse
    {
        return $this->responder->build($this->resolver->resolve($request));
    }

    /** POST — add an item */
    public function addToCart(AddToCartRequest $request, AddToCartAction $action): JsonResponse
    {
        $cart = $this->resolver->resolve($request);
        $action->execute($cart, $request->validated());

        return $this->responder->build($cart);
    }

    /** PATCH — update quantity / attributes of an existing item */
    public function updateCartItem(
        UpdateCartItemRequest $request,
        CartItem              $item,
        UpdateCartItemAction  $action,
    ): JsonResponse {
        $cart = $this->resolver->resolve($request);

        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item, $request->validated());

        return $this->responder->build($cart);
    }

    /** DELETE — remove an item */
    public function removeCartItem(
        Request              $request,
        CartItem             $item,
        RemoveCartItemAction $action,
    ): JsonResponse {
        $cart = $this->resolver->resolve($request);

        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item);

        return $this->responder->build($cart);
    }

    /** PATCH — change selected price-filter categories (e.g. cash/installment) */
    public function updateCartFilters(
        UpdateCartFiltersRequest $request,
        SyncCartFiltersAction    $action,
    ): JsonResponse {
        $cart = $this->resolver->resolve($request);
        $action->execute($cart, $request->validated('filter_categories', []));

        return $this->responder->build($cart);
    }

    /** PATCH — change customer type (real person / legal entity / ...) */
    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolver->resolve($request);
        $cart->update(['customer_type_id' => $request->validated('customer_type_id')]);

        return $this->responder->build($cart);
    }
}

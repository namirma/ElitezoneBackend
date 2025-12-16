<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Support\Facades\DB;

class RemoveCartItemAction
{
    public function execute(CartItem $cartItem): Cart
    {
        return DB::transaction(function () use ($cartItem) {
            $cart = $cartItem->cart;

            $cartItem->delete();

            if ($cart->items()->count() === 0) {
                $cart->priceFilterCategories()->detach();
            }

            return $cart->load(['items.productVariant', 'items.parameterValues']);
        });
    }
}

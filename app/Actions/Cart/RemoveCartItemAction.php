<?php

namespace App\Actions\Cart;

use App\Events\CartItemChanged;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use Illuminate\Support\Facades\DB;

class RemoveCartItemAction
{
    public function execute(CartItem $cartItem): Cart
    {
        return DB::transaction(function () use ($cartItem) {
            $cart = $cartItem->cart;

            /* -----------------------------------------------------------------
             * گام ۱: استخراج اطلاعات برای دیتاساینس (قبل از حذف)
             * ----------------------------------------------------------------- */
            $variant = $cartItem->productVariant;
            
            $quantityRemoved = (float) $cartItem->quantity * -1;

            $priceSnapshot = $cartItem->final_price > 0 ? $cartItem->final_price : ($variant->price ?? 0);
            /* ----------------------------------------------------------------- */

            // گام ۲: عملیات اصلی حذف
            $cartItem->delete();

            if ($cart->items()->count() === 0) {
                $cart->priceFilterCategories()->detach();
            }

            /* -----------------------------------------------------------------
             * گام ۳: ارسال ایونت (بعد از حذف موفق)
             * ----------------------------------------------------------------- */
            CartItemChanged::dispatch(
                $cart,
                $variant,
                CartEvent::ACTION_REMOVE,
                $quantityRemoved,
                $priceSnapshot
            );
            /* ----------------------------------------------------------------- */

            return $cart->fresh(['items.productVariant', 'items.parameterValues']);
        });
    }
}

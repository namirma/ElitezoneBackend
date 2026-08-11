<?php

namespace App\Actions\Cart;

use App\Events\Cart\CartItemChanged;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Services\Cart\CartParkService;
use Illuminate\Support\Facades\DB;

class RemoveCartItemAction
{
    public function __construct(
        protected CartParkService $parkService,
    ) {}

    public function execute(CartItem $cartItem): Cart
    {
        return DB::transaction(function () use ($cartItem) {
            $cart = $cartItem->cart;

            // قفلِ ردیفِ سبد — هم‌سو با AddToCartAction — برای جلوگیری از race با افزودن/به‌روزرسانی.
            Cart::where('id', $cart->id)->lockForUpdate()->first();

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

            // با خالی‌شدن سبد، مهلت و قفلِ تأمین‌کننده برداشته می‌شوند — وگرنه
            // سبدِ بعدی مهلتِ کهنه را به ارث می‌برد و به تأمین‌کننده‌ی قبلی
            // قفل می‌ماند. حذفِ یک قلم از سبدِ چندقلمی مهلت را **دست نمی‌زند**
            // (بند ۳.۱: ویرایش مهلت را ریست نمی‌کند).
            $this->parkService->releaseIfEmpty($cart);

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

            return $cart->fresh(['items.productVariant']);
        });
    }
}

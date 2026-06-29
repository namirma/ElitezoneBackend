<?php

namespace App\Listeners\Cart;

use App\Events\Cart\CartItemChanged;
use App\Models\CartEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogCartEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CartItemChanged $event): void
    {
        CartEvent::create([
            CartEvent::CART_ID => $event->cart->id,
            CartEvent::USER_ID => $event->cart->user_id,
            CartEvent::SESSION_ID => $event->cart->session_id,
            CartEvent::PRODUCT_VARIANT_ID => $event->variant->id,
            CartEvent::ACTION_TYPE => $event->actionType,
            CartEvent::QUANTITY_CHANGE => $event->quantityChange,
            CartEvent::UNIT_PRICE_SNAPSHOT => $event->currentPrice,
        ]);
    }
}

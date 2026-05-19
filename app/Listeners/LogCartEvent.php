<?php

namespace App\Listeners;

use App\Events\CartItemChanged;
use App\Models\CartEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogCartEvent implements ShouldQueue
{
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

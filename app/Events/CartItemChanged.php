<?php

namespace App\Events;

use App\Models\Cart;
use App\Models\ProductVariant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartItemChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public ProductVariant $variant,
        public string $actionType,
        public float $quantityChange,
        public ?float $currentPrice = null
    ) {}
}

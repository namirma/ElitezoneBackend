<?php

namespace App\Events\Cart;

use App\Models\Cart;
use App\Models\ProductVariant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartItemChanged implements ShouldDispatchAfterCommit
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

<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class CartIsEmptyException extends BusinessException
{
    public function __construct(?string $message = null)
    {
        $msg = $message ?? __('cart.cart_is_empty');
        parent::__construct($msg, 400);
    }
}

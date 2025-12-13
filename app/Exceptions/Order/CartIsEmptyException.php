<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class CartIsEmptyException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(__('cart.cart_is_empty'), 400);
    }
}

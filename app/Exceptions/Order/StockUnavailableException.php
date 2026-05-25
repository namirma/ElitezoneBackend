<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class StockUnavailableException extends BusinessException
{
    public function __construct(string $productName)
    {
        parent::__construct(
            __('orders.errors.stock_unavailable', ['product' => $productName]),
            422
        );
    }
}

<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class OrderNotPayableException extends BusinessException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'این سفارش قابل پرداخت نیست.', 400);
    }
}

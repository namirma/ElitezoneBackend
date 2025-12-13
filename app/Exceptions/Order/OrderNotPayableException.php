<?php

namespace App\Exceptions\Order;
use App\Exceptions\BusinessException;

class OrderNotPayableException extends BusinessException
{
    public function __construct(string $status)
    {
        parent::__construct("سفارش با وضعیت '$status' قابل پرداخت نیست.", 400);
    }
}

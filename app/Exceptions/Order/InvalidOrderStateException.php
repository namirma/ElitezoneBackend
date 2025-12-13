<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class InvalidOrderStateException extends BusinessException
{
    public function __construct(string $currentStatus, string $action)
    {
        parent::__construct("انجام عملیات '$action' روی سفارش با وضعیت '$currentStatus' مجاز نیست.", 422);
    }
}

<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class StockUnavailableException extends BusinessException
{
    public function __construct(string $productName)
    {
        parent::__construct($message ?? 'موجودی محصول کافی نیست.', 422);
    }
}

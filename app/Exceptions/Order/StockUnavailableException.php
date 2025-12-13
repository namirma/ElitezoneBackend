<?php

namespace App\Exceptions\Order;

use App\Exceptions\BusinessException;

class StockUnavailableException extends BusinessException
{
    public function __construct(string $productName)
    {
        parent::__construct("موجودی محصول '{$productName}' کافی نیست.", 422);
    }
}

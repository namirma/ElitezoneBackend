<?php

namespace App\Exceptions\Wallet;

use App\Exceptions\BusinessException;

class InsufficientBalanceException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(__('wallet.insufficient_balance'), 402); // 402 Payment Required
    }
}

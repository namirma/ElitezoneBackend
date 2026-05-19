<?php

namespace App\Exceptions\Wallet;

use App\Exceptions\BusinessException;

class InsufficientBalanceException extends BusinessException
{
    public function __construct(?string $message = null)
    {
        $msg = $message ?? __('wallet.errors.insufficient_balance');

        parent::__construct($msg, 402);
    }
}

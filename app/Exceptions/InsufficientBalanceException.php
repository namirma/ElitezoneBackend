<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InsufficientBalanceException extends BusinessException
{
    public function __construct(float $required, float $available)
    {
        $shortage = $required - $available;
        parent::__construct(
            __('wallet.insufficient_balance_with_shortage', ['shortage' => number_format($shortage)]),
            Response::HTTP_PAYMENT_REQUIRED
        );
    }
}

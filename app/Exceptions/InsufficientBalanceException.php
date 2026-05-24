<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InsufficientBalanceException extends BusinessException
{
    public readonly float $required;
    public readonly float $available;

    public function __construct(float $required, float $available)
    {
        $this->required  = $required;
        $this->available = $available;
        $shortage = $required - $available;
        parent::__construct(
            __('wallet.insufficient_balance_with_shortage', ['shortage' => number_format($shortage)]),
            Response::HTTP_PAYMENT_REQUIRED
        );
    }
}

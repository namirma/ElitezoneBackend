<?php

// app/Exceptions/Wallet/WalletNotFoundException.php

namespace App\Exceptions\Wallet;

use App\Exceptions\BusinessException;
use Symfony\Component\HttpFoundation\Response;

class WalletNotFoundException extends BusinessException
{
    public function __construct(string $message)
    {
        parent::__construct($message, Response::HTTP_NOT_FOUND);
    }
}

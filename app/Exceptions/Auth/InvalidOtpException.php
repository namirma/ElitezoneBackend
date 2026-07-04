<?php

namespace App\Exceptions\Auth;

use App\Exceptions\BusinessException;

class InvalidOtpException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(__('auth.code_invalid'), 422);
    }
}

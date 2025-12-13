<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BusinessException extends Exception
{
    protected int $httpCode = 400;

    public function __construct(string $message, int $httpCode = 400)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
    public function render(Request $request): JsonResponse
    {
        return error($this->getMessage(), [], $this->httpCode);
    }
}

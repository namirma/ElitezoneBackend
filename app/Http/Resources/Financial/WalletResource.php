<?php

namespace App\Http\Resources\Financial;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'balance' => (string) $this->balance,       // موجودی قابل برداشت
            'balance_hold' => (string) $this->balance_hold,  // موجودی رزرو شده
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}

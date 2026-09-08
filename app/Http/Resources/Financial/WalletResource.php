<?php

namespace App\Http\Resources\Financial;

use App\Http\Resources\BaseJsonResource;
use App\Models\Wallet;
use Illuminate\Http\Request;

/**
 * @mixin Wallet
 */
class WalletResource extends BaseJsonResource
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

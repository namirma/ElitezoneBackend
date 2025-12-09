<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlaceOrderData
{
    public function __construct(
        public readonly ?int $beneficiaryUserId,
        public readonly ?int $addressId,
        public readonly ?string $description = null,
        public readonly ?string $shippingMethod = null, 
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            beneficiaryUserId: $request->input('beneficiary_user_id'),
            addressId: $request->input('address_id'),
            description: $request->input('description')
        );
    }
}

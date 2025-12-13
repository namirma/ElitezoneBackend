<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

class PlaceOrderData
{
    public function __construct(
        public readonly ?int $beneficiaryUserId,
        public readonly ?int $addressId,
        public readonly ?string $description,
        public readonly ?string $shippingMethod,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            beneficiaryUserId: $request->integer('beneficiary_user_id') ?: null,
            addressId: $request->integer('address_id') ?: null,
            description: $request->input('description'),
            shippingMethod: $request->input('shipping_method'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    public function toArray(): array
    {
        return [
            'beneficiary_user_id' => $this->beneficiaryUserId,
            'address_id'          => $this->addressId,
            'description'         => $this->description,
            'shipping_method'     => $this->shippingMethod,
            'ip_address'          => $this->ipAddress,
            'user_agent'          => $this->userAgent,
        ];
    }
}

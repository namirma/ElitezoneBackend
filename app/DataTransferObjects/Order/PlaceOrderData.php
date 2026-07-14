<?php

namespace App\DataTransferObjects\Order;

use App\Http\Requests\Order\StoreOrderRequest;

class PlaceOrderData
{
    public function __construct(
        /** گیرنده سفارش — کارفرمایی که الیت یار برایش خریداری می‌کند (nullable) */
        public readonly ?int $beneficiaryUserId,
        /** الیت یار که کمیسیون CO را دریافت می‌کند (nullable) */
        public readonly ?int $consultantUserId,
        public readonly ?int $addressId,
        public readonly ?int $shippingMethodId,
        public readonly ?string $description,
        public readonly ?string $couponCode,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {}

    /**
     * ساخت DTO از ریکوئست معتبر شده
     */
    public static function fromRequest(StoreOrderRequest $request): self
    {
        $data = $request->validated();

        return new self(
            beneficiaryUserId: isset($data['beneficiary_user_id']) ? (int) $data['beneficiary_user_id'] : null,
            consultantUserId: isset($data['consultant_user_id']) ? (int) $data['consultant_user_id'] : null,
            addressId: isset($data['address_id']) ? (int) $data['address_id'] : null,
            shippingMethodId: isset($data['shipping_method_id']) ? (int) $data['shipping_method_id'] : null,
            description: $data['description'] ?? null,
            couponCode: $data['coupon_code'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    public function toArray(): array
    {
        return [
            'beneficiary_user_id' => $this->beneficiaryUserId,
            'consultant_user_id' => $this->consultantUserId,
            'address_id' => $this->addressId,
            'shipping_method_id' => $this->shippingMethodId,
            'description' => $this->description,
            'coupon_code' => $this->couponCode,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }
}

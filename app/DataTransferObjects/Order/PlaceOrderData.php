<?php

namespace App\DataTransferObjects\Order;

use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Cart;
use Illuminate\Http\Request;

class PlaceOrderData
{
    public function __construct(
        /** گیرنده سفارش — کارفرمایی که الیت یار برایش خریداری می‌کند (nullable) */
        public readonly ?int $beneficiaryUserId,
        /** الیت یار که کمیسیون CO را دریافت می‌کند (nullable) */
        public readonly ?int $eliteYARUserId,
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
            eliteYARUserId: isset($data['elite_yar_user_id']) ? (int) $data['elite_yar_user_id'] : null,
            addressId: isset($data['address_id']) ? (int) $data['address_id'] : null,
            shippingMethodId: isset($data['shipping_method_id']) ? (int) $data['shipping_method_id'] : null,
            description: $data['description'] ?? null,
            couponCode: $data['coupon_code'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    /**
     * ساخت DTO از خودِ سبد — مسیرِ «کارفرما لینک را باز می‌کند و می‌پردازد» (فاز ۶).
     *
     * اینجا هیچ بدنه‌ای از سمتِ کارفرما خوانده نمی‌شود و این عمدی است: آدرس،
     * روش ارسال و کد تخفیف را الیت‌یار موقعِ بستنِ سبد انتخاب کرده و روی خودِ
     * سبد نشسته‌اند. اگر کارفرما می‌توانست این‌ها را در لحظه‌ی پرداخت عوض کند،
     * چیزی را می‌پرداخت که با آنچه در لینک دیده بود یکی نبود.
     */
    public static function fromCart(Cart $cart, Request $request): self
    {
        return new self(
            beneficiaryUserId: (int) $cart->{Cart::BENEFICIARY_USER_ID},
            // الیت‌یارِ مؤثر را خودِ `PlaceOrderAction` از نقشِ صاحبِ سبد درمی‌آورد.
            eliteYARUserId: null,
            addressId: $cart->{Cart::ADDRESS_ID} ? (int) $cart->{Cart::ADDRESS_ID} : null,
            shippingMethodId: $cart->{Cart::SHIPPING_METHOD_ID} ? (int) $cart->{Cart::SHIPPING_METHOD_ID} : null,
            description: null,
            couponCode: $cart->{Cart::COUPON_CODE} ?: null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    public function toArray(): array
    {
        return [
            'beneficiary_user_id' => $this->beneficiaryUserId,
            'elite_yar_user_id' => $this->eliteYARUserId,
            'address_id' => $this->addressId,
            'shipping_method_id' => $this->shippingMethodId,
            'description' => $this->description,
            'coupon_code' => $this->couponCode,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }
}

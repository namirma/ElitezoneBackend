<?php

namespace App\DataTransferObjects\Pricing;

readonly class PriceCalculationResult
{
    public function __construct(
        public readonly float $baseGeneralPrice,
        public readonly float $baseSpecialPrice,
        public readonly float $priceAfterVariantDiscount,
        public readonly float $listPrice,
        public readonly float $payablePrice,
        public readonly float $customerAdjustment,
        public readonly float $finalPrice,
        public readonly float $vatAmount,
        public readonly float $vatRate,
        public readonly float $priceWithVat,
        public readonly bool $withVat,
        public readonly ?string $variantDiscountType,
        public readonly ?float $variantDiscountValue,
        public readonly float $variantDiscountAmount,
        public readonly array $breakdown = [],
        /** قیمتِ ثبت‌شده‌ی تأمین‌کننده (پیش از فیلترهای نقش) — مبنای PTS. پرسشِ ۶ مدیریت. */
        public readonly float $supplierPrice = 0.0,
        public readonly bool $priceFloorApplied = false,
        /** درصدِ «تخفیف» روی کارتِ محصول برای خریدارِ دارای الیت‌یار (مدیریت، دورِ پنجم، پرسشِ ۱). */
        public readonly float $buyerDiscountPercent = 0.0,
    ) {}

    /**
     * متد کمکی برای تبدیل به آرایه
     */
    public function toArray(): array
    {
        return [
            'base_general_price' => (string) $this->baseGeneralPrice,
            'base_special_price' => (string) $this->baseSpecialPrice,
            'price_after_variant_discount' => (string) $this->priceAfterVariantDiscount,
            'general_price_with_filters' => (string) $this->listPrice,
            'special_price_with_filters' => (string) $this->payablePrice,
            'customer_adjustment' => (string) $this->customerAdjustment,
            'role_golden_figure' => (string) max(0.0, $this->listPrice - $this->payablePrice),
            'final_price' => (string) $this->finalPrice,
            'vat_amount' => (string) $this->vatAmount,
            'vat_rate' => (string) $this->vatRate,
            'price_with_vat' => (string) $this->priceWithVat,
            'with_vat' => $this->withVat,
            'variant_discount_type' => $this->variantDiscountType,
            'variant_discount_value' => $this->variantDiscountValue !== null ? (string) $this->variantDiscountValue : null,
            'variant_discount_amount' => (string) $this->variantDiscountAmount,
            'supplier_price' => (string) $this->supplierPrice,
            'price_floor_applied' => $this->priceFloorApplied,
            // کارتِ محصول: «تخفیف» = این درصد از قیمتِ با مالیات؛ مبلغِ قطعی در صفحه‌ی سفارش.
            'buyer_discount_percent' => (string) $this->buyerDiscountPercent,
            'price_after_buyer_discount' => (string) round($this->priceWithVat * (1 - $this->buyerDiscountPercent / 100), 4),
            'breakdown' => $this->breakdown,
        ];
    }
}

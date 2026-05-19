<?php

namespace App\Services\Pricing;

use App\DataTransferObjects\Pricing\PriceCalculationResult;
use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductVariant;
use App\Models\User;

class PricingContext
{
    // === ورودی‌ها (Inputs) ===
    public function __construct(
        public ProductVariant $variant,
        public Factory $factory,
        public FactoryLocation $location,
        public ?CustomerType $customerType = null,
        public ?Badge $badge = null,
        public ?array $filterCategoryIds = null,
        public bool $withVat = false,
        public ?string $filterCategoryCode = null,
        public ?User $user = null
    ) {}

    // === وضعیت محاسباتی (State) ===
    public float $baseGeneralPrice = 0.0;

    public float $baseSpecialPrice = 0.0;

    // مسیری که قیمت نهایی را می‌سازد (Special Path)
    public float $payablePrice = 0.0;

    // مسیری که قیمت خط‌خورده را می‌سازد (General Path)
    public float $listPrice = 0.0;

    public float $customerAdjustment = 0.0;

    public float $vatAmount = 0.0;

    public float $vatRate = 0.0;

    // === گزارش (Logs/Breakdown) ===
    public array $breakdown = [];

    // اطلاعات تخفیف واریانت (برای نمایش)
    public ?string $variantDiscountType = null;

    public ?float $variantDiscountValue = null;

    public float $variantDiscountAmount = 0.0;

    /**
     * افزودن یک گزارش به لیست جزئیات
     */
    public function log(string $key, mixed $value, ?string $description = null): void
    {
        $this->breakdown[$key] = [
            'value' => $value,
            'description' => $description,
        ];
    }

    /**
     * خروجی نهایی استاندارد (مشابه خروجی قبلی سرویس)
     */
    public function toArray(): array
    {
        $finalPrice = max(0.0, $this->payablePrice);
        $totalWithVat = $finalPrice + $this->vatAmount;

        return [
            'base_general_price' => (string) $this->baseGeneralPrice,
            'base_special_price' => (string) $this->baseSpecialPrice,
            'variant_discount_type' => $this->variantDiscountType,
            'variant_discount_value' => $this->variantDiscountValue !== null ? (string) $this->variantDiscountValue : null,
            'variant_discount_amount' => (string) $this->variantDiscountAmount,

            'general_price_with_filters' => (string) $this->listPrice,
            'special_price_with_filters' => (string) $this->payablePrice,

            'customer_adjustment' => (string) $this->customerAdjustment,
            'role_golden_figure' => (string) max(0.0, $this->listPrice - $this->payablePrice),

            'final_price' => (string) $finalPrice,
            'vat_amount' => (string) $this->vatAmount,
            'vat_rate' => (string) $this->vatRate,
            'price_with_vat' => (string) $totalWithVat,
            'with_vat' => $this->withVat,
            'debug_breakdown' => $this->breakdown,
        ];
    }

    /**
     * تبدیل کانتکست نهایی به آبجکت DTO
     */
    public function toDto(): PriceCalculationResult
    {
        $finalPrice = max(0.0, $this->payablePrice);
        $totalWithVat = $finalPrice + $this->vatAmount;

        $priceAfterDiscount = $this->baseGeneralPrice;
        if ($this->baseSpecialPrice > 0) {
            $priceAfterDiscount = $this->baseSpecialPrice - $this->variantDiscountAmount;
        }

        return new PriceCalculationResult(
            baseGeneralPrice: $this->baseGeneralPrice,
            baseSpecialPrice: $this->baseSpecialPrice,
            priceAfterVariantDiscount: max(0.0, $priceAfterDiscount),
            listPrice: $this->listPrice,
            payablePrice: $this->payablePrice,
            customerAdjustment: $this->customerAdjustment,
            finalPrice: $finalPrice,
            vatAmount: $this->vatAmount,
            vatRate: $this->vatRate,
            priceWithVat: $totalWithVat,
            withVat: $this->withVat,
            variantDiscountType: $this->variantDiscountType,
            variantDiscountValue: $this->variantDiscountValue,
            variantDiscountAmount: $this->variantDiscountAmount,
            breakdown: $this->breakdown
        );
    }
}

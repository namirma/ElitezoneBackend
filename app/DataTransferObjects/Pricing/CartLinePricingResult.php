<?php

namespace App\DataTransferObjects\Pricing;

readonly class CartLinePricingResult
{
    public function __construct(
        public readonly int $cartItemId,
        public readonly float $baseQuantity,
        public readonly float $unitPrice,
        public readonly float $unitVatAmount,
        public readonly float $variantDiscountPerUnit,
        public readonly float $lineSubtotal,
        public readonly float $lineVat,
        public readonly float $lineTotal,
        public readonly array $unitPriceDetails,
    ) {}
}

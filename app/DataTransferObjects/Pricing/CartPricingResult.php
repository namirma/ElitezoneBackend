<?php

namespace App\DataTransferObjects\Pricing;

class CartPricingResult
{
    /**
     * @param  CartLinePricingResult[]  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly float $vatAmount,
        public readonly float $totalVariantDiscount,
        public readonly float $offerDiscount,
        public readonly float $shippingCost,
        public readonly float $total,
        public readonly array $appliedOffers,
    ) {}
}

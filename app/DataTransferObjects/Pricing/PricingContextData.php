<?php

namespace App\DataTransferObjects\Pricing;

use App\Models\Badge;
use App\Models\CustomerType;

class PricingContextData
{
    public function __construct(
        public readonly string $activeCode,
        public readonly ?CustomerType $customerType,
        public readonly ?Badge $badge,
        /** @var int[] */
        public readonly array $filterCategoryIds,
        public readonly bool $withVat = true,
        public readonly string $offerBasis = 'weight',
    ) {}
}

<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceSetting;
use App\Services\Pricing\PricingContext;
use Closure;

class ApplyVat implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        if ($context->withVat) {
            $context->vatRate = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);

            $rateStr = bcdiv((string) $context->vatRate, '100', 4);
            $vatAmountStr = bcmul((string) $context->payablePrice, $rateStr, 4);

            $context->vatAmount = (float) $vatAmountStr;
        }

        return $next($context);
    }
}

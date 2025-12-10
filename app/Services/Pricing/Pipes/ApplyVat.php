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
            // دریافت نرخ مالیات از تنظیمات
            $context->vatRate = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);

            // محاسبه مبلغ مالیات روی قیمت نهایی قابل پرداخت
            $context->vatAmount = $context->payablePrice * ($context->vatRate / 100);
        }

        return $next($context);
    }
}

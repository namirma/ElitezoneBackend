<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;

class ApplyVariantDiscount implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        // دریافت اطلاعات تخفیف از مدل
        $discountDetails = $context->variant->getActiveDiscountDetails();

        if ($discountDetails) {
            $context->variantDiscountType = $discountDetails['type'];
            $context->variantDiscountValue = (float) $discountDetails['value'];

            // تخفیف روی "قیمت قابل پرداخت" اعمال می‌شود
            $baseForDiscount = $context->payablePrice;
            $amount = 0.0;

            if ($discountDetails['type'] === 'percentage') {
                $amount = $baseForDiscount * ($context->variantDiscountValue / 100);
            } elseif ($discountDetails['type'] === 'fixed_amount') {
                $amount = $context->variantDiscountValue;
            }

            $context->variantDiscountAmount = $amount;

            // اعمال تخفیف
            $context->payablePrice -= $amount;

            // جلوگیری از قیمت منفی
            $context->payablePrice = max(0.0, $context->payablePrice);
        }

        return $next($context);
    }
}

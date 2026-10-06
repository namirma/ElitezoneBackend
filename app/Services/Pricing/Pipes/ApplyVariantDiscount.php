<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;

class ApplyVariantDiscount implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        // «به‌جز تخفیفِ خریدارِ دارای الیت‌یار و کوپن، هیچ تخفیفِ دیگری در سیستم وجود ندارد» —
        // مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴). داده‌ی تخفیفِ واریانت می‌ماند (قانونِ یک)، فقط اعمال نمی‌شود.
        if (! config('features.variant_discounts')) {
            return $next($context);
        }

        $discountDetails = $context->variant->getActiveDiscountDetails();

        if ($discountDetails) {
            $context->variantDiscountType = $discountDetails['type'];
            $context->variantDiscountValue = (float) $discountDetails['value'];

            $baseForDiscount = (string) $context->payablePrice;
            $amountStr = '0.0000';

            if ($discountDetails['type'] === 'percentage') {

                $rateStr = bcdiv((string) $context->variantDiscountValue, '100', 4);
                $amountStr = bcmul($baseForDiscount, $rateStr, 4);
            } elseif ($discountDetails['type'] === 'fixed_amount') {
                $amountStr = (string) $context->variantDiscountValue;
            }

            $context->variantDiscountAmount = (float) $amountStr;

            $newPriceStr = bcsub($baseForDiscount, $amountStr, 4);
            $context->payablePrice = max(0.0, (float) $newPriceStr);
        }

        return $next($context);
    }
}

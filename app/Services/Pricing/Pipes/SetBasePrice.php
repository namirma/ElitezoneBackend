<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Facades\Cache;

class SetBasePrice implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        // ساخت کلید کش
        $cacheKey = "price_{$context->variant->id}_{$context->factory->id}_{$context->location->id}";

        $prices = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($context) {
            $priceModel = $context->variant->prices()
                ->where('factory_id', $context->factory->id)
                ->where('factory_location_id', $context->location->id)
                ->latest('updated_at') 
                ->first();

            if ($priceModel) {
                return [
                    'general' => (float) $priceModel->general_price,
                    'special' => (float) $priceModel->special_price,
                ];
            }

            return ['general' => 0.0, 'special' => 0.0];
        });

        $context->baseGeneralPrice = $prices['general'];
        $context->baseSpecialPrice = $prices['special'];

        $context->listPrice = $context->baseGeneralPrice;

        $initialSpecial = $prices['special'];
        $context->payablePrice = $initialSpecial > 0 ? $initialSpecial : $context->baseGeneralPrice;

        return $next($context);
    }
}

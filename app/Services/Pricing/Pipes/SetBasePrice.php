<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Facades\Cache;

class SetBasePrice implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        $variantId  = $context->variant->id;
        $factoryId  = $context->factory->id;
        $locationId = $context->location->id;

        $cacheKey = "price_v{$variantId}_f{$factoryId}_l{$locationId}";
        $ttl = now()->addMinutes(60);

        $fetchPriceLogic = function () use ($context) {
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
        };

        // تلاش برای استفاده از کش هوشمند
        try {
            $tags = ['pricing', "variant:{$variantId}"];
            $prices = Cache::tags($tags)->remember($cacheKey, $ttl, $fetchPriceLogic);
        } catch (\BadMethodCallException $e) {
            $prices = Cache::remember($cacheKey, $ttl, $fetchPriceLogic);
        }

        $context->baseGeneralPrice = $prices['general'];
        $context->baseSpecialPrice = $prices['special'];

        $context->listPrice = $context->baseGeneralPrice;

        $initialSpecial = $prices['special'];
        $context->payablePrice = $initialSpecial > 0 ? $initialSpecial : $context->baseGeneralPrice;

        return $next($context);
    }
}

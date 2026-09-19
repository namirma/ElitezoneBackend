<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Facades\Cache;

class SetBasePrice implements PricingPipe
{
    /**
     * تنها نقطه ساخت کلید کش قیمت پایه — ProductPrice::flushVariantCache هم
     * برای invalidation روی درایورهای بدون tag از همین متد استفاده می‌کند.
     */
    public static function cacheKey(int $variantId, int $factoryId, int $locationId): string
    {
        return "pricing:base:v{$variantId}:f{$factoryId}:l{$locationId}";
    }

    public function handle(PricingContext $context, Closure $next)
    {
        $variantId = $context->variant->id;
        $factoryId = $context->factory->id;
        $locationId = $context->location->id;

        $cacheKey = self::cacheKey((int) $variantId, (int) $factoryId, (int) $locationId);
        $ttl = now()->addMinutes(60);

        $fetchPriceLogic = function () use ($context) {
            $factoryId = $context->factory->id;
            $locationId = $context->location->id;

            // اگر رابطه‌ی prices از قبل eager-load شده (کاری که لیستِ محصولات/واریانت انجام
            // می‌دهد)، به‌جای یک کوئریِ per-row روی cache-miss، از همان مجموعه‌ی لودشده فیلتر
            // می‌کنیم (رفعِ N+1 روی مسیرِ داغِ قیمت‌گذاری). در نبودِ رابطه‌ی لودشده، کوئری می‌زنیم.
            if ($context->variant->relationLoaded('prices')) {
                $priceModel = $context->variant->prices
                    ->where('factory_id', $factoryId)
                    ->where('factory_location_id', $locationId)
                    ->sortByDesc('updated_at')
                    ->first();
            } else {
                $priceModel = $context->variant->prices()
                    ->where('factory_id', $factoryId)
                    ->where('factory_location_id', $locationId)
                    ->latest('updated_at')
                    ->first();
            }

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

<?php

namespace App\Services\Pricing;

use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Support\Collection;

/**
 * Hydrates role-based pricing onto product variants and their loaded prices.
 *
 * Runs the pricing pipeline (via PriceCalculationService) for each price row of
 * a variant, decorating it with `final_price`, `role_*` figures and `vat_rate`,
 * and sets the variant's `min_price`. This is the single source of truth for the
 * role-aware price decoration that ProductVariantResource / VariantInWishlistResource /
 * SubscribedVariantResource serialize.
 */
class VariantPriceHydrator
{
    public function __construct(
        private readonly PriceCalculationService $priceService
    ) {}

    /**
     * Hydrate a collection of variants (each must have its `prices` relation loaded
     * together with `prices.factory` and `prices.factoryLocation`).
     */
    public function hydrateMany(Collection $variants, string $roleCode, bool $debug = false): void
    {
        $variants->each(fn ($variant) => $this->hydrate($variant, $roleCode, $debug));
    }

    public function hydrate(ProductVariant $variant, string $roleCode, bool $debug = false): void
    {
        if (! $variant->relationLoaded('prices')) {
            return;
        }

        $variant->prices->each(function ($price) use ($variant, $roleCode, $debug) {
            $dto = $this->priceService->calculatePrice(
                variant: $variant,
                factory: $price->factory,
                location: $price->factoryLocation,
                customerType: null,
                badge: null,
                filterCategoryIds: null,
                // withVAT: true فقط `vatAmount`/`vatRate`/`priceWithVat` را پر می‌کند؛
                // `finalPrice` در هر دو حالت همان `payablePrice` است (ApplyVat اصلاً
                // payablePrice را دست نمی‌زند)، پس قیمتِ بدونِ مالیات تغییری نمی‌کند.
                //
                // قبلاً false بود و در نتیجه `vatRate` صفر می‌ماند — یعنی خطِ
                // `$price->vat_rate = $dto->vatRate` عملاً مرده بود و فرانت هیچ راهی
                // برای دانستنِ نرخ نداشت، پس در باکس خرید `× ۱٫۱۰` هاردکد شده بود.
                withVAT: true,
                filterCategoryCode: $roleCode
            );

            $price->final_price = $dto->finalPrice;
            $price->role_special_price = $dto->payablePrice;
            $price->role_general_price = $dto->listPrice;
            $price->role_golden_figure = max(0.0, $dto->listPrice - $dto->payablePrice);
            $price->vat_rate = $dto->vatRate;
            $price->vat_amount = $dto->vatAmount;
            $price->price_with_vat = $dto->priceWithVat;

            if ($debug) {
                $price->pricing_debug = $dto->toArray();
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }
}

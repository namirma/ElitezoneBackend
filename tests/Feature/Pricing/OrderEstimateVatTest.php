<?php

namespace Tests\Feature\Pricing;

use App\Actions\Order\CalculateOrderEstimateAction;
use App\Models\Offer;
use App\Models\OrderUnit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The order-estimate (pre-invoice / پیش‌فاکتور) endpoint must agree with the cart:
 *
 *  1. `finalPrice` from the pricing pipeline is already the EX-VAT payable price
 *     (priceWithVat = finalPrice + vatAmount), so the subtotal is weight × finalPrice —
 *     VAT is added on top, never subtracted from it. The old code computed
 *     `weight × (finalPrice − vat)`, which cancelled the VAT and made `final_price`
 *     omit VAT entirely when with_vat = true.
 *  2. A cart-level offer that reduces the subtotal reduces VAT proportionally
 *     (VAT applies to the post-discount base — same rule as CartPricingCalculator).
 *
 * Numbers are identical to CartOfferVatTest so both code paths provably produce the
 * same пред-invoice: subtotal 1,000,000; offer 20% = 200,000; VAT 100,000 × 0.8 = 80,000;
 * final = 800,000 + 80,000 = 880,000.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class OrderEstimateVatTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    #[Test]
    public function the_estimate_adds_vat_on_top_and_reduces_it_proportionally_for_offers(): void
    {
        // مثل `CartOfferVatTest` — این تست خودِ قابلیتِ آفر را می‌سنجد، پس فلگش را
        // روشن می‌کند. `features.product_offers` پیش‌فرض خاموش است چون
        // `PlaceOrderAction` تخفیفِ آفر را ثبت نمی‌کند؛ رفتارِ «مالیات به‌تناسبِ
        // تخفیف کم شود» که اینجا پین شده با روشن شدن فلگ برقرار می‌ماند.
        config(['features.product_offers' => true]);

        Cache::flush();
        $this->setVatRate(10);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_quantity' => 1000]);
        // special == general ⇒ finalPrice = 1,000,000 ex-VAT, no variant discount.
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 1_000_000);

        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);

        $offer = Offer::create([
            Offer::TITLE => 'تخفیف تست',
            Offer::TYPE => 'percentage',
            Offer::VALUE => 20,
            Offer::MINIMUM_QUANTITY => 0,
            Offer::IS_ACTIVE => true,
        ]);
        $product->offers()->attach($offer->id, ['associated_at' => now()]);

        $result = app(CalculateOrderEstimateAction::class)->execute(
            [
                'variant_id' => $variant->id,
                'factory_id' => $factory->id,
                'location_id' => $location->id,
                'quantities' => [(string) $unit->id => 1],
                'with_vat' => true,
            ],
            'employer_without_elite_yar',
            null,
        );

        $fin = $result['financials'];

        // subtotal is the ex-VAT goods value (weight × finalPrice), NOT finalPrice − VAT.
        $this->assertEqualsWithDelta(1_000_000, (float) $fin['subtotal'], 1);
        $this->assertEqualsWithDelta(200_000, (float) $fin['discount_amount'], 1);
        // VAT follows the discount: 100,000 × (800,000 / 1,000,000) = 80,000.
        $this->assertEqualsWithDelta(80_000, (float) $fin['vat_amount'], 1);
        // final = 800,000 + 80,000 = 880,000 (the old bug gave 820,000 and dropped a VAT step).
        $this->assertEqualsWithDelta(880_000, (float) $fin['final_price'], 1);
    }
}

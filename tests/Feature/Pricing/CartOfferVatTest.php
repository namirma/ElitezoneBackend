<?php

namespace Tests\Feature\Pricing;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Pricing\CartPricingCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A cart-level offer that reduces the subtotal must also reduce VAT proportionally.
 * Per the spec ("پروسه خرید"), VAT applies to the post-discount base — so the buyer must
 * not be charged VAT on the pre-offer amount.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class CartOfferVatTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    #[Test]
    public function a_cart_offer_reduces_vat_proportionally(): void
    {
        Cache::flush();
        $this->setVatRate(10);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_quantity' => 1000]);
        // special == general so finalPrice = 1,000,000 ex-VAT, no variant discount.
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 1_000_000);

        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);

        // 20% offer on the whole product, no quantity threshold.
        $offer = Offer::create([
            Offer::TITLE => 'تخفیف تست',
            Offer::TYPE => 'percentage',
            Offer::VALUE => 20,
            Offer::MINIMUM_QUANTITY => 0,
            Offer::IS_ACTIVE => true,
        ]);
        $product->offers()->attach($offer->id, ['associated_at' => now()]);

        $sm = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);
        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        CartItem::create([
            CartItem::CART_ID => $cart->id,
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $sm->id,
            CartItem::QUANTITIES => [(string) $unit->id => 1],
            CartItem::WEIGHT => 0, CartItem::QUANTITY => 0, CartItem::UNIT_NAME => 'عدد',
            CartItem::PRICE => 0, CartItem::FINAL_PRICE => 0, CartItem::VAT_AMOUNT => 0, CartItem::TOTAL_WITH_VAT => 0,
        ]);

        $ctx = new PricingContextData(
            activeCode: 'employer_without_elite_yar',
            customerType: null,
            badge: null,
            filterCategoryIds: [],
            withVat: true,
            offerBasis: 'weight',
        );

        $result = app(CartPricingCalculator::class)->calculate($cart->fresh(), $ctx);

        // subtotal 1,000,000; offer 20% = 200,000; afterOffer 800,000.
        $this->assertEqualsWithDelta(1_000_000, $result->subtotal, 1);
        $this->assertEqualsWithDelta(200_000, $result->offerDiscount, 1);
        // VAT must follow the discount: 100,000 × (800,000/1,000,000) = 80,000 (not 100,000).
        $this->assertEqualsWithDelta(80_000, $result->vatAmount, 1);
        // total = 800,000 + 80,000 = 880,000 (the old bug gave 900,000).
        $this->assertEqualsWithDelta(880_000, $result->total, 1);
    }
}

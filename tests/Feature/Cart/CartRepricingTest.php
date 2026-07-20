<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Services\Cart\CartResponseBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the cart re-pricing fix: when a cart is read, each line's stored snapshot
 * (price / final_price / vat_amount / total_with_vat) must be refreshed to the price
 * computed for the CURRENT pricing role — so a line frozen under one role (e.g. a guest
 * cart added before login) can never display a stale amount next to the live per-kg price.
 *
 * DatabaseTransactions: writes roll back at teardown; the live DB is not mutated.
 */
class CartRepricingTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    #[Test]
    public function building_the_cart_reprices_a_stale_line_snapshot(): void
    {
        Cache::flush();
        $this->setVatRate(10);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 900_000);

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(4),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(4),
        ]);

        $cart = Cart::create([Cart::SESSION_ID => (string) Str::uuid()]);

        // Stale snapshot — deliberately wrong numbers, as if frozen under another role.
        $item = $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => 100],
            CartItem::WEIGHT => 100,
            CartItem::QUANTITY => 100,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 1,
            CartItem::FINAL_PRICE => 1,
            CartItem::VAT_AMOUNT => 1,
            CartItem::TOTAL_WITH_VAT => 1,
            CartItem::WITH_VAT => true,
        ]);

        // Clear the price/units caches so the pipeline recomputes from scratch.
        Cache::flush();

        app(CartResponseBuilder::class)->build($cart);

        $item->refresh();

        // special_price = 900,000 ex-VAT; VAT 10% → 90,000 per kg; weight = 100 kg.
        $expectedFinal = 900_000.0;
        $expectedTotal = 100 * (900_000 + 90_000); // 99,000,000

        $this->assertEqualsWithDelta($expectedFinal, (float) $item->final_price, 1.0,
            'Stale line price must be re-priced to the role-based final price');
        $this->assertEqualsWithDelta((float) $expectedTotal, (float) $item->total_with_vat, 100.0,
            'Stale total_with_vat must be re-priced to baseQty × (finalPrice + VAT)');
    }
}

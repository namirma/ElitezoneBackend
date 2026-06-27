<?php

namespace Tests\Feature\Cart;

use App\Actions\Cart\UpdateCartItemAction;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * UpdateCartItemAction must apply the chosen `location_id` (the field
 * UpdateCartItemRequest validates) and keep `factory_id` consistent. A previous bug read
 * a non-existent `factory_location_id` key, so the required location change was silently
 * dropped and the line re-priced against the old origin.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class UpdateCartLocationTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    #[Test]
    public function updating_a_cart_item_applies_the_new_location_and_factory(): void
    {
        Cache::flush(); // pricing + order-unit caches are not rolled back between tests
        $this->setVatRate(10);

        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_quantity' => 1000]);

        $factoryA = $this->makeFactory();
        $locA = $this->makeLocation($factoryA);
        $factoryB = $this->makeFactory();
        $locB = $this->makeLocation($factoryB);

        $this->makePrice($variant, $factoryA, $locA);
        $this->makePrice($variant, $factoryB, $locB);

        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);
        $product->orderUnits()->attach($unit->id, ['max_quantity' => 1000]);
        $sm = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);

        $item = CartItem::create([
            CartItem::CART_ID => $cart->id,
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factoryA->id,
            CartItem::FACTORY_LOCATION_ID => $locA->id,
            CartItem::SHIPPING_METHOD_ID => $sm->id,
            CartItem::QUANTITIES => [(string) $unit->id => 5],
            CartItem::WEIGHT => 0,
            CartItem::QUANTITY => 0,
            CartItem::UNIT_NAME => 'عدد',
            CartItem::PRICE => 0,
            CartItem::FINAL_PRICE => 0,
            CartItem::VAT_AMOUNT => 0,
            CartItem::TOTAL_WITH_VAT => 0,
        ]);

        // Realistic validated payload from UpdateCartItemRequest (it carries `location_id`).
        app(UpdateCartItemAction::class)->execute($item, [
            'quantities' => [(string) $unit->id => 5],
            'location_id' => $locB->id,
        ]);

        $fresh = $item->fresh();
        $this->assertSame($locB->id, $fresh->factory_location_id, 'location_id must be applied');
        $this->assertSame($factoryB->id, $fresh->factory_id, 'factory_id must follow the new location');
    }
}

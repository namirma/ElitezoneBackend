<?php

namespace Tests\Feature\Order;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Events\Order\OrderPlaced;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * PlaceOrderAction must NOT subtract the variant discount twice.
 *
 * `finalPrice` (from the pricing pipeline) is already the post-discount, ex-VAT unit
 * price and `vatAmount` is VAT on that post-discount base. So the order subtotal
 * (Σ weight × finalPrice) already reflects the discount. The old code then did
 * `final_amount = subtotal + vat + shipping − totalDiscount`, subtracting the discount
 * a SECOND time and understating the order total (it no longer matched the cart total
 * the buyer saw, and could mis-route the reservation-vs-direct threshold check).
 *
 * Scenario: special = general = 1,000,000 ex-VAT, 10% variant discount, VAT 10%, qty 1
 * (order_unit.amount = 1 ⇒ weight = 1). No shipping_rates row ⇒ freight = 0.
 *   finalPrice = 900,000   vat = 90,000   variantDiscount = 100,000
 *   correct final_amount = 900,000 + 90,000 + 0 = 990,000
 *   old buggy value       = 990,000 − 100,000  = 890,000
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class PlaceOrderFinalAmountTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    /** @test */
    public function it_does_not_subtract_the_variant_discount_twice_from_final_amount(): void
    {
        Cache::flush();
        Event::fake([OrderPlaced::class]);
        $this->setVatRate(10);

        $supplier = User::factory()->create();
        $buyer = User::factory()->create();

        $factory = $this->makeFactory();
        $factory->update([\App\Models\Factory::SUPPLIER_USER_ID => $supplier->id]);

        $location = $this->makeLocation($factory);
        $product = $this->makeProduct();
        // 10% active variant discount; stock high enough for the deduction step.
        $variant = $this->makeVariant($product, [
            'stock_quantity' => 1000,
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);
        // special == general ⇒ pre-discount payable = 1,000,000 ex-VAT.
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 1_000_000);

        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);
        $sm = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);

        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::POSTAL_ADDRESS => 'تهران، خیابان آزادی، پلاک ۱',
            Address::RECEIVER_NAME => 'علی محمدی',
            Address::RECEIVER_MOBILE => '09121111111',
        ]);

        $cart = Cart::create([Cart::USER_ID => $buyer->id]);
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

        $data = new PlaceOrderData(
            beneficiaryUserId: null,
            eliteYARUserId: null,
            addressId: $address->id,
            shippingMethodId: $sm->id,
            description: null,
            couponCode: null,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        );

        $order = app(PlaceOrderAction::class)->execute($buyer, $data);

        // subtotal is post-discount (900,000); discount is recorded for display only.
        $this->assertEqualsWithDelta(900_000, (float) $order->{Order::SUBTOTAL}, 1);
        $this->assertEqualsWithDelta(100_000, (float) $order->{Order::DISCOUNT_AMOUNT}, 1);
        $this->assertEqualsWithDelta(90_000, (float) $order->{Order::VAT_AMOUNT}, 1);
        // The discount must NOT be subtracted again: 900,000 + 90,000 = 990,000 (not 890,000).
        $this->assertEqualsWithDelta(990_000, (float) $order->{Order::FINAL_AMOUNT}, 1);
    }
}

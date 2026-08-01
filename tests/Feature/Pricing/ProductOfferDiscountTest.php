<?php

namespace Tests\Feature\Pricing;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Pricing\CartPricingCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تخفیفِ آفر — گاردِ مالی و دو نقصِ محاسباتی.
 *
 * سبد خرید تخفیفی را نشان می‌داد که `PlaceOrderAction` هرگز اعمال نمی‌کرد
 * (`orders` ستونی برایش ندارد، و هیچ کنترلر/روت/seederی هم برای ساختِ آفر
 * وجود ندارد). پس خریدار یک عدد می‌دید و عددِ بزرگ‌تری می‌پرداخت. ماژول حالا
 * پشتِ `features.product_offers` است و وقتی روشن شود این دو باگ را ندارد:
 *
 *   ۱. `is_active` چک نمی‌شد — آفرِ غیرفعالِ بدونِ `end_date` تا ابد تخفیف می‌داد.
 *   ۲. درصدِ یک محصول روی **کلِ سبد** اعمال می‌شد، نه سطرِ خودش.
 */
class ProductOfferDiscountTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private OrderUnit $unit;

    private ShippingMethod $shippingMethod;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setVatRate(10);
        $this->unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);
        $this->shippingMethod = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);
    }

    private function context(): PricingContextData
    {
        return new PricingContextData(
            activeCode: 'employer_without_elite_yar',
            customerType: null,
            badge: null,
            filterCategoryIds: [],
            withVat: true,
            offerBasis: 'weight',
        );
    }

    private function addLine(Cart $cart, ProductVariant $variant, $factory, $location): void
    {
        CartItem::create([
            CartItem::CART_ID => $cart->id,
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shippingMethod->id,
            CartItem::QUANTITIES => [(string) $this->unit->id => 1],
            CartItem::WEIGHT => 0, CartItem::QUANTITY => 0, CartItem::UNIT_NAME => 'عدد',
            CartItem::PRICE => 0, CartItem::FINAL_PRICE => 0, CartItem::VAT_AMOUNT => 0, CartItem::TOTAL_WITH_VAT => 0,
        ]);
    }

    #[Test]
    public function offers_are_off_by_default(): void
    {
        $this->assertFalse(
            (bool) config('features.product_offers'),
            'تا وقتی تخفیفِ آفر در سمتِ سفارش ثبت نمی‌شود، این فلگ باید خاموش بماند — وگرنه سبد عددی را نشان می‌دهد که پرداخت نمی‌شود.'
        );
    }

    #[Test]
    public function the_cart_shows_no_offer_discount_while_the_flag_is_off(): void
    {
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_quantity' => 1000]);
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 1_000_000);

        $offer = Offer::create([
            Offer::TITLE => 'تخفیف تست', Offer::TYPE => 'percentage',
            Offer::VALUE => 20, Offer::MINIMUM_QUANTITY => 0, Offer::IS_ACTIVE => true,
        ]);
        $product->offers()->attach($offer->id, ['associated_at' => now()]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        $this->addLine($cart, $variant, $factory, $location);

        $result = app(CartPricingCalculator::class)->calculate($cart->fresh(), $this->context());

        $this->assertSame(0.0, $result->offerDiscount);
        $this->assertEqualsWithDelta(1_100_000, $result->total, 1, 'بدون آفر: ۱٬۰۰۰٬۰۰۰ + ۱۰٪ مالیات.');
    }

    #[Test]
    public function an_inactive_offer_never_discounts(): void
    {
        config(['features.product_offers' => true]);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_quantity' => 1000]);
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 1_000_000);

        // end_date عمداً NULL — پیش‌فرضِ مایگریشن، و همان حالتی که آفرِ غیرفعال را
        // تا ابد زنده نگه می‌داشت چون تنها شرطِ چک‌شده تاریخ بود.
        $offer = Offer::create([
            Offer::TITLE => 'آفر خاموش', Offer::TYPE => 'percentage',
            Offer::VALUE => 20, Offer::MINIMUM_QUANTITY => 0,
            Offer::IS_ACTIVE => false, Offer::END_DATE => null,
        ]);
        $product->offers()->attach($offer->id, ['associated_at' => now()]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        $this->addLine($cart, $variant, $factory, $location);

        $result = app(CartPricingCalculator::class)->calculate($cart->fresh(), $this->context());

        $this->assertSame(0.0, $result->offerDiscount, 'آفرِ غیرفعال نباید هیچ تخفیفی بدهد.');
    }

    #[Test]
    public function a_percentage_offer_is_scoped_to_its_own_line_not_the_whole_cart(): void
    {
        config(['features.product_offers' => true]);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        // سطرِ کوچکِ دارای آفر (۱۰٬۰۰۰) کنارِ سطرِ بزرگِ بدونِ آفر (۹۹۰٬۰۰۰).
        $smallProduct = $this->makeProduct();
        $small = $this->makeVariant($smallProduct, ['stock_quantity' => 1000]);
        $this->makePrice($small, $factory, $location, general: 10_000, special: 10_000);

        $largeProduct = $this->makeProduct();
        $large = $this->makeVariant($largeProduct, ['stock_quantity' => 1000]);
        $this->makePrice($large, $factory, $location, general: 990_000, special: 990_000);

        $offer = Offer::create([
            Offer::TITLE => 'آفر سطرِ کوچک', Offer::TYPE => 'percentage',
            Offer::VALUE => 10, Offer::MINIMUM_QUANTITY => 0, Offer::IS_ACTIVE => true,
        ]);
        $smallProduct->offers()->attach($offer->id, ['associated_at' => now()]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        $this->addLine($cart, $small, $factory, $location);
        $this->addLine($cart, $large, $factory, $location);

        $result = app(CartPricingCalculator::class)->calculate($cart->fresh(), $this->context());

        // ۱۰٪ از ۱۰٬۰۰۰ = ۱٬۰۰۰. پیش از اصلاح ۱۰٪ از کلِ ۱٬۰۰۰٬۰۰۰ یعنی
        // ۱۰۰٬۰۰۰ بود — ده برابرِ ارزشِ سطری که آن را کسب کرده بود.
        $this->assertEqualsWithDelta(1_000, $result->offerDiscount, 1);
    }
}

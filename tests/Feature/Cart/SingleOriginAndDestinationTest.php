<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryLocation;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Cart\CartParkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * یک سفارش = یک مبدأ و یک مقصد — بند ۳ سند، تصمیم مدیریت.
 *
 * قفلِ تک‌تأمین‌کننده این را **نمی‌پوشاند**: یک تأمین‌کننده می‌تواند چند
 * کارخانه/انبار داشته باشد، پس سبدِ کاملاً معتبرِ تک‌تأمین‌کننده هم می‌توانست
 * چندمبدأیی شود. زیرسیستم چندمحموله‌ای دست‌نخورده می‌ماند (عودت و حمل مجدد به
 * آن نیاز دارند)؛ فقط دیگر بیش از یک لِگ ساخته نمی‌شود.
 */
class SingleOriginAndDestinationTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private Cart $cart;

    private FactoryLocation $originA;

    private FactoryLocation $originB;

    private ShippingMethod $shippingMethod;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $product = $this->makeProduct();
        $this->variantId = (int) $this->makeVariant($product, ['stock_quantity' => 1000])->id;

        $this->originA = $this->makeLocation($this->makeFactory());
        $this->originB = $this->makeLocation($this->makeFactory());

        $this->shippingMethod = ShippingMethod::create([
            'name' => 'truck',
            'code' => 'truck_'.uniqid(),
        ]);

        $this->cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
    }

    private function service(): CartParkService
    {
        return app(CartParkService::class);
    }

    /**
     * ‏`factory_location_id` روی جدول **NOT NULL** است، پس ردیفِ بی‌مبدأ اصلاً
     * وجود ندارد؛ `whereNotNull` در گارد صرفاً دفاعی است.
     */
    private function addItem(FactoryLocation $origin, ?int $addressId = null): CartItem
    {
        return CartItem::create([
            CartItem::CART_ID => $this->cart->id,
            CartItem::PRODUCT_VARIANT_ID => $this->variantId,
            CartItem::FACTORY_ID => $origin->factory_id,
            CartItem::FACTORY_LOCATION_ID => $origin->id,
            CartItem::ADDRESS_ID => $addressId,
            CartItem::SHIPPING_METHOD_ID => $this->shippingMethod->id,
            CartItem::QUANTITIES => [],
            CartItem::PRICE => 0,
            CartItem::FINAL_PRICE => 0,
        ]);
    }

    // ── مبدأ ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_second_origin_is_refused_with_422(): void
    {
        $this->addItem($this->originA);

        try {
            $this->service()->assertSingleOriginAllowed($this->cart, (int) $this->originB->id);
            $this->fail('افزودن کالای انبارِ دوم باید رد شود.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->render(request())->getStatusCode());
            $this->assertSame(__('cart.limits.single_origin_only'), $e->getMessage());
        }
    }

    #[Test]
    public function the_same_origin_passes(): void
    {
        $this->addItem($this->originA);

        $this->service()->assertSingleOriginAllowed($this->cart, (int) $this->originA->id);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_empty_cart_accepts_any_origin(): void
    {
        // سبدِ خالی‌شده باید بتواند مبدأ عوض کند — به همین دلیل قاعده با
        // ردیف‌های موجود سنجیده می‌شود و نه با یک ستونِ قفل روی سبد که بعداً
        // باید جداگانه پاک شود.
        $this->service()->assertSingleOriginAllowed($this->cart, (int) $this->originB->id);

        $this->addToAssertionCount(1);
    }

    // ── مقصد ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_second_destination_is_refused_with_422(): void
    {
        $this->addItem($this->originA, addressId: 5);

        try {
            $this->service()->assertSingleDestinationAllowed($this->cart, 6);
            $this->fail('افزودن کالا با مقصدِ دوم باید رد شود.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->render(request())->getStatusCode());
            $this->assertSame(__('cart.limits.single_destination_only'), $e->getMessage());
        }
    }

    #[Test]
    public function a_null_destination_means_the_orders_primary_address_and_never_conflicts(): void
    {
        // `null` یعنی «همان آدرسِ اصلیِ سفارش»، پس با هر مقصدِ صریحی سازگار
        // است. تضاد فقط بین دو آدرسِ **صریحِ** متفاوت است.
        $this->addItem($this->originA, addressId: 5);

        $this->service()->assertSingleDestinationAllowed($this->cart, null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_same_destination_passes(): void
    {
        $this->addItem($this->originA, addressId: 5);

        $this->service()->assertSingleDestinationAllowed($this->cart, 5);

        $this->addToAssertionCount(1);
    }
}

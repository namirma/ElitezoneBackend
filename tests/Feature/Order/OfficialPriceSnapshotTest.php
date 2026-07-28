<?php

namespace Tests\Feature\Order;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Events\Order\OrderPlaced;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Factory;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Financial\LedgerReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * `official_price` باید میانگینِ وزنیِ ردیف‌ها باشد.
 *
 * تسویه با `OP × weight_before_scale` کار می‌کند. تا پیش از این هیچ‌کس این ستون را
 * نمی‌نوشت، پس هر سه مسیرِ تسویه روی فالبکِ `items->first()->unit_price` می‌افتادند
 * — یعنی **کلِ تناژِ سفارش به قیمتِ ردیفِ اول** حساب می‌شد.
 *
 * سناریوی این تست همان حالتِ عادیِ B2B است که گاردِ تک‌تأمین‌کننده اجازه‌اش می‌دهد:
 * دو کالای متفاوت از **یک** کارخانه.
 *
 *   ردیف ۱: ۵ واحد × ۱٬۰۰۰٬۰۰۰ =  ۵٬۰۰۰٬۰۰۰
 *   ردیف ۲: ۵ واحد × ۱٬۲۰۰٬۰۰۰ =  ۶٬۰۰۰٬۰۰۰
 *   ارزش واقعی کالا            = ۱۱٬۰۰۰٬۰۰۰   وزن کل = ۱۰
 *
 *   درست  → OP = ۱٬۱۰۰٬۰۰۰  ⇒ OP × ۱۰ = ۱۱٬۰۰۰٬۰۰۰ ✅
 *   قبلاً → OP = ۱٬۰۰۰٬۰۰۰  ⇒ OP × ۱۰ = ۱۰٬۰۰۰٬۰۰۰ ❌ (یک میلیون کمتر)
 */
class OfficialPriceSnapshotTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    #[Test]
    public function a_two_line_order_snapshots_the_weighted_average_not_the_first_lines_price(): void
    {
        Cache::flush();
        Event::fake([OrderPlaced::class]);
        $this->setVatRate(10);

        $supplier = User::factory()->create();
        $buyer = User::factory()->create();

        // یک کارخانه برای هر دو ردیف — پس گاردِ assertSingleSupplier رد نمی‌کند.
        $factory = $this->makeFactory();
        $factory->update([Factory::SUPPLIER_USER_ID => $supplier->id]);
        $location = $this->makeLocation($factory);

        $product = $this->makeProduct();
        $cheap = $this->makeVariant($product, ['stock_quantity' => 1000]);
        $dear = $this->makeVariant($product, ['stock_quantity' => 1000]);

        $this->makePrice($cheap, $factory, $location, general: 1_000_000, special: 1_000_000);
        $this->makePrice($dear, $factory, $location, general: 1_200_000, special: 1_200_000);

        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);
        $sm = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);

        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::POSTAL_ADDRESS => 'تهران، خیابان آزادی، پلاک ۱',
            Address::RECEIVER_NAME => 'علی محمدی',
            Address::RECEIVER_MOBILE => '09121111111',
        ]);

        $cart = Cart::create([Cart::USER_ID => $buyer->id]);
        foreach ([$cheap, $dear] as $variant) {
            CartItem::create([
                CartItem::CART_ID => $cart->id,
                CartItem::PRODUCT_VARIANT_ID => $variant->id,
                CartItem::FACTORY_ID => $factory->id,
                CartItem::FACTORY_LOCATION_ID => $location->id,
                CartItem::SHIPPING_METHOD_ID => $sm->id,
                CartItem::QUANTITIES => [(string) $unit->id => 5],
                CartItem::WEIGHT => 0, CartItem::QUANTITY => 0, CartItem::UNIT_NAME => 'عدد',
                CartItem::PRICE => 0, CartItem::FINAL_PRICE => 0,
                CartItem::VAT_AMOUNT => 0, CartItem::TOTAL_WITH_VAT => 0,
            ]);
        }

        $order = app(PlaceOrderAction::class)->execute($buyer, new PlaceOrderData(
            beneficiaryUserId: null,
            eliteYARUserId: null,
            addressId: $address->id,
            shippingMethodId: $sm->id,
            description: null,
            couponCode: null,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        ));

        $op = (float) $order->{Order::OFFICIAL_PRICE};
        $weight = (float) $order->{Order::WEIGHT_BEFORE_SCALE};

        $this->assertEqualsWithDelta(10, $weight, 0.001, 'وزن کل باید ۱۰ باشد.');
        $this->assertEqualsWithDelta(1_100_000, $op, 1, 'official_price باید میانگینِ وزنی باشد.');

        // نکتهٔ اصلی: حاصل‌ضربی که تسویه استفاده می‌کند باید ارزشِ واقعیِ کالا را بدهد.
        $this->assertEqualsWithDelta(11_000_000, $op * $weight, 10);

        // و همان چیزی که قبلاً تولید می‌شد، صریحاً رد شود.
        $this->assertNotEqualsWithDelta(1_000_000, $op, 1, 'نباید قیمتِ ردیفِ اول باشد.');
    }

    #[Test]
    public function a_freshly_placed_order_is_clean_under_financial_doctor(): void
    {
        // بررسیِ سرتاسری: چیزی که PlaceOrderAction می‌نویسد باید از invariant تازهٔ
        // financial:doctor رد شود — وگرنه هر سفارشِ جدید بلافاصله علامت می‌خورد.
        Cache::flush();
        Event::fake([OrderPlaced::class]);
        $this->setVatRate(10);

        $supplier = User::factory()->create();
        $buyer = User::factory()->create();

        $factory = $this->makeFactory();
        $factory->update([Factory::SUPPLIER_USER_ID => $supplier->id]);
        $location = $this->makeLocation($factory);

        $product = $this->makeProduct();
        $a = $this->makeVariant($product, ['stock_quantity' => 1000]);
        $b = $this->makeVariant($product, ['stock_quantity' => 1000]);
        $this->makePrice($a, $factory, $location, general: 940_000, special: 940_000);
        $this->makePrice($b, $factory, $location, general: 1_310_000, special: 1_310_000);

        $unit = OrderUnit::create(['name' => 'کیلو', 'code' => 'kg_'.uniqid(), 'unit' => 'kg', 'amount' => 1]);
        $sm = ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()]);

        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::POSTAL_ADDRESS => 'اصفهان، فولادشهر',
            Address::RECEIVER_NAME => 'رضا احمدی',
            Address::RECEIVER_MOBILE => '09131111111',
        ]);

        $cart = Cart::create([Cart::USER_ID => $buyer->id]);
        foreach ([[$a, 3], [$b, 7]] as [$variant, $qty]) {
            CartItem::create([
                CartItem::CART_ID => $cart->id,
                CartItem::PRODUCT_VARIANT_ID => $variant->id,
                CartItem::FACTORY_ID => $factory->id,
                CartItem::FACTORY_LOCATION_ID => $location->id,
                CartItem::SHIPPING_METHOD_ID => $sm->id,
                CartItem::QUANTITIES => [(string) $unit->id => $qty],
                CartItem::WEIGHT => 0, CartItem::QUANTITY => 0, CartItem::UNIT_NAME => 'کیلو',
                CartItem::PRICE => 0, CartItem::FINAL_PRICE => 0,
                CartItem::VAT_AMOUNT => 0, CartItem::TOTAL_WITH_VAT => 0,
            ]);
        }

        $order = app(PlaceOrderAction::class)->execute($buyer, new PlaceOrderData(
            beneficiaryUserId: null,
            eliteYARUserId: null,
            addressId: $address->id,
            shippingMethodId: $sm->id,
            description: null,
            couponCode: null,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        ));

        $this->assertSame(
            [],
            app(LedgerReconciler::class)->mispricedOrders($order->id),
            'سفارشِ تازه‌ساخته‌شده نباید mispriced علامت بخورد.'
        );
    }
}

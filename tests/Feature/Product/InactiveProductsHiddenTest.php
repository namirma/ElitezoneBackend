<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\AttributeValue;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * محصول/واریانتِ غیرفعال از کاتالوگِ عمومی پنهان است (علی ۱۴۰۵/۰۷/۱۹) — فهرست، جزئیات،
 * فیلترها و قیمتِ واریانت. سبدی که از قبل آن را دارد نمی‌شکند.
 */
class InactiveProductsHiddenTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->token = 'tok'.Str::lower(Str::random(10));
    }

    private function product(bool $active = true): Product
    {
        $category = ProductCategory::create([
            'name' => 'دسته',
            'slug' => 'c-'.Str::random(8),
            'code' => 'C_'.Str::random(6),
        ]);

        return Product::create([
            Product::CATEGORY_ID => $category->id,
            Product::NAME => "میلگرد {$this->token} ".Str::random(4),
            Product::SLUG => 'p-'.Str::random(10),
            Product::SKU => 'SKU-'.Str::random(8),
            Product::IS_ACTIVE => $active,
        ]);
    }

    private function variant(Product $product, bool $active = true): ProductVariant
    {
        return $this->makeVariant($product, [ProductVariant::IS_ACTIVE => $active]);
    }

    #[Test]
    public function an_inactive_product_is_not_in_the_public_list(): void
    {
        $live = $this->product();
        $hidden = $this->product(active: false);

        $cuids = collect($this->getJson("/api/products?search={$this->token}&per_page=100")->assertOk()->json('data.data'))
            ->pluck('cuid');

        $this->assertContains($live->cuid, $cuids);
        $this->assertNotContains($hidden->cuid, $cuids);
    }

    #[Test]
    public function an_inactive_variant_is_not_in_the_variant_list(): void
    {
        $product = $this->product();
        $live = $this->variant($product);
        $hidden = $this->variant($product, active: false);

        $listed = collect($this->getJson("/api/products/{$product->cuid}/variants?per_page=100")->assertOk()->json('data.data'))
            ->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertContains($live->id, $listed);
        $this->assertNotContains($hidden->id, $listed);
    }

    #[Test]
    public function the_detail_endpoints_of_an_inactive_product_are_404(): void
    {
        $product = $this->product(active: false);
        $variant = $this->variant($product);

        $this->getJson("/api/products/{$product->cuid}")->assertNotFound();
        $this->getJson("/api/products/{$product->cuid}/variants")->assertNotFound();
        $this->getJson("/api/products/{$product->cuid}/filters")->assertNotFound();
        $this->getJson("/api/products/variants/{$variant->id}")->assertNotFound();

        // همان محصول وقتی فعال است دیده می‌شود — ۴۰۴ از غیرفعال‌بودن است، نه از fixture.
        $product->update([Product::IS_ACTIVE => true]);
        $this->getJson("/api/products/{$product->cuid}")->assertOk();
        $this->getJson("/api/products/variants/{$variant->id}")->assertOk();
    }

    #[Test]
    public function an_inactive_variant_has_no_detail_and_no_price(): void
    {
        $product = $this->product();
        $variant = $this->variant($product, active: false);
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $this->makePrice($variant, $factory, $location);

        $this->getJson("/api/products/variants/{$variant->id}")->assertNotFound();
        $this->postJson('/api/products/variant-price', [
            'variant_id' => $variant->id,
            'factory_id' => $factory->id,
            'location_id' => $location->id,
        ])->assertNotFound();
    }

    #[Test]
    public function a_filter_option_reachable_only_through_an_inactive_variant_is_not_offered(): void
    {
        $product = $this->product();
        $live = $this->variant($product);
        $hidden = $this->variant($product, active: false);

        $u = Str::random(6);
        $type = AttributeType::create([AttributeType::NAME => "t-{$u}", AttributeType::DISPLAY_NAME => 'سایز', AttributeType::INPUT_TYPE => 'select']);
        $attribute = Attribute::create([Attribute::ATTRIBUTE_TYPE_ID => $type->id, Attribute::NAME => "a-{$u}", Attribute::DISPLAY_NAME => 'قطر']);
        $shown = AttributeValue::create([AttributeValue::ATTRIBUTE_ID => $attribute->id, AttributeValue::VALUE => '12', AttributeValue::DISPLAY_VALUE => '۱۲']);
        $dead = AttributeValue::create([AttributeValue::ATTRIBUTE_ID => $attribute->id, AttributeValue::VALUE => '14', AttributeValue::DISPLAY_VALUE => '۱۴']);
        foreach ([[$live, $shown], [$hidden, $dead]] as [$variant, $value]) {
            ProductVariantAttribute::create([
                ProductVariantAttribute::PRODUCT_VARIANT_ID => $variant->id,
                ProductVariantAttribute::ATTRIBUTE_ID => $attribute->id,
                ProductVariantAttribute::ATTRIBUTE_VALUE_ID => $value->id,
            ]);
        }

        $valueIds = collect($this->getJson("/api/products/{$product->cuid}/filters")->assertOk()->json('data'))
            ->flatMap(fn ($t) => $t['attributes'] ?? [])
            ->flatMap(fn ($a) => $a['values'] ?? [])
            ->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertContains($shown->id, $valueIds);
        $this->assertNotContains($dead->id, $valueIds);
    }

    #[Test]
    public function a_cart_that_already_holds_an_inactive_product_still_renders(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $this->makePrice($variant, $factory, $location);
        $unit = OrderUnit::create(['name' => 'عدد', 'code' => 'pcs_'.uniqid(), 'unit' => 'pcs', 'amount' => 1]);
        $product->orderUnits()->attach($unit->id, ['max_quantity' => 1000]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        CartItem::create([
            CartItem::CART_ID => $cart->id,
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => ShippingMethod::create(['name' => 'truck', 'code' => 'truck_'.uniqid()])->id,
            CartItem::QUANTITIES => [(string) $unit->id => 5],
            CartItem::WEIGHT => 0,
            CartItem::QUANTITY => 0,
            CartItem::UNIT_NAME => 'عدد',
            CartItem::PRICE => 0,
            CartItem::FINAL_PRICE => 0,
            CartItem::VAT_AMOUNT => 0,
            CartItem::TOTAL_WITH_VAT => 0,
        ]);

        $product->update([Product::IS_ACTIVE => false]);
        $variant->update([ProductVariant::IS_ACTIVE => false]);

        Sanctum::actingAs($user);
        $items = $this->getJson('/api/cart')->assertOk()->json('data.cart.items');

        $this->assertCount(1, $items, 'سبد باید همچنان قلمِ موجود را نشان دهد.');
    }
}

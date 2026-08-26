<?php

namespace Tests\Feature\Product;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/products/variants/{variant} — direct variant lookup by numeric id,
 * independent of pagination and of which "main product" it belongs to.
 *
 * Before this endpoint, the frontend's /product/{id} deep link only ever searched
 * the currently-loaded (paginated, single-main-product) variants list in Redux —
 * a variant on page 2, or belonging to a second product in the same category,
 * could never be found and the page showed "محصول موردنظر یافت نشد" even though
 * the product genuinely existed.
 */
class ProductVariantDeepLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function makeProductWithVariant(string $productName): ProductVariant
    {
        $category = ProductCategory::create([
            'name' => 'دسته تست دیپ‌لینک',
            'slug' => 'deep-link-cat-'.Str::random(8),
            'code' => 'DL_'.Str::random(6),
        ]);

        $product = Product::create([
            'cuid' => Str::random(20),
            'category_id' => $category->id,
            'name' => $productName,
            'slug' => 'deep-link-product-'.Str::random(8),
            'sku' => 'SKU_'.Str::random(6),
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'واریانت تست دیپ‌لینک',
            'sku' => 'VSKU_'.Str::random(6),
        ]);
    }

    #[Test]
    public function it_finds_a_variant_belonging_to_a_second_main_product(): void
    {
        // شبیه‌سازیِ همان چیزی که دیپ‌لینک را می‌شکست: دو محصولِ اصلیِ جدا در یک
        // دسته — لیستِ صفحه‌بندی‌شده‌ی فرانت فقط اولی را از قبل در Redux دارد.
        $this->makeProductWithVariant('محصول اول');
        $secondVariant = $this->makeProductWithVariant('محصول دوم');

        $this->getJson("/api/products/variants/{$secondVariant->id}")
            ->assertOk()
            ->assertJsonPath('data.variant.id', $secondVariant->id)
            ->assertJsonPath('data.product.name', 'محصول دوم');
    }

    #[Test]
    public function it_includes_price_information(): void
    {
        $variant = $this->makeProductWithVariant('محصول با قیمت');

        $factory = Factory::create([
            Factory::NAME => 'کارخانه تست',
            Factory::SLUG => 'factory-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
        $location = FactoryLocation::create([
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار تست',
            FactoryLocation::TYPE => 'warehouse',
        ]);
        ProductPrice::create([
            ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
            ProductPrice::GENERAL_PRICE => 1_000_000,
            ProductPrice::SPECIAL_PRICE => 900_000,
            ProductPrice::GOLDEN_FIGURE => 950_000,
        ]);

        $response = $this->getJson("/api/products/variants/{$variant->id}")->assertOk();

        $this->assertNotEmpty($response->json('data.variant.prices'));
    }

    #[Test]
    public function a_nonexistent_variant_id_is_a_404(): void
    {
        $this->getJson('/api/products/variants/999999999')->assertNotFound();
    }
}

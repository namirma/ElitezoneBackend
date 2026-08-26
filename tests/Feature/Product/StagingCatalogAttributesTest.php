<?php

namespace Tests\Feature\Product;

use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StagingCatalogSeeder;
use Database\Seeders\UserTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سایدبارِ فیلترِ محصول روی استیجینگ کاملاً خالی بود.
 *
 * ‏`StagingCatalogSeeder` محصول و قیمت و عکس می‌ساخت ولی **هیچ ردیفی در
 * `attribute_values` و `product_variant_attributes` نمی‌نوشت**، و
 * `ProductSeeder` هم گزینه‌ها را بدونِ ردیفِ مقدار می‌ساخت — یعنی گزینه‌هایی که
 * به هیچ واریانتی قابلِ وصل نبودند (`attribute_value_id` ستونِ NOT NULL است).
 * نتیجه: `GET /products/{cuid}/filters` آرایه‌ی خالی، واریانت‌ها بدونِ چیپ،
 * بدونِ هیچ خطایی در هیچ لاگی.
 */
class StagingCatalogAttributesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // زیرسیستمِ موجودیِ per-origin (`factory_stocks`) هنوز به برنچِ L13
        // پورت نشده و `StagingCatalogSeeder` آنجا روی همان کلاس می‌افتد — یعنی
        // آن سیدر روی L13 از قبل و مستقل از این تغییر خراب است. همان قراردادی
        // که تست‌های `LedgerReconciler` دارند: skip به‌جای فورک‌کردنِ فایل، تا
        // این تست بین دو برنچ بایت‌به‌بایت یکی بماند.
        if (! class_exists(\App\Models\FactoryStock::class)) {
            $this->markTestSkipped('factory_stocks is not on this branch — StagingCatalogSeeder cannot run.');
        }

        // دیسکِ `public` در این محیط روی S3 است و `ensurePlaceholderImages()`
        // واقعاً به شبکه می‌زند — کُند و بی‌ربط به چیزی که اینجا سنجیده می‌شود.
        Storage::fake('public');

        // `StagingCatalogSeeder` روی دسته‌ها/کارخانه‌ها/واحدهای سفارشِ
        // `ProductSeeder` سوار می‌شود و بدونشان با warn بیرون می‌آید؛
        // `UserTypeSeeder` هم نقشِ `supplier` را می‌سازد.
        $this->seed(UserTypeSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StagingCatalogSeeder::class);
    }

    private function demoProduct(string $slug): Product
    {
        return Product::where(Product::SLUG, $slug)->firstOrFail();
    }

    #[Test]
    public function every_seeded_variant_carries_attributes(): void
    {
        $variants = ProductVariant::where(ProductVariant::SKU, 'like', 'DEMO-%')->get();

        $this->assertNotEmpty($variants, 'سیدرِ کاتالوگ هیچ واریانتی نساخت.');

        foreach ($variants as $variant) {
            $this->assertNotEmpty(
                $variant->variantAttributes,
                "واریانت {$variant->sku} هیچ ویژگی‌ای ندارد — سایدبارش خالی می‌ماند."
            );
        }
    }

    #[Test]
    public function the_filters_endpoint_returns_groups_with_their_options(): void
    {
        $product = $this->demoProduct('rebar-plain-a1');

        $groups = collect(
            $this->getJson("/api/products/{$product->cuid}/filters")
                ->assertOk()
                ->json('data')
        )->keyBy(AttributeType::NAME);

        // عنوانِ گروه از `attribute_types` می‌آید و گزینه‌ها از `attributes` —
        // اگر این دو سطح جابه‌جا شوند فیلتر بی‌اثر می‌شود (تستِ بعدی).
        $this->assertSame('نوع محصول', $groups['type'][AttributeType::DISPLAY_NAME]);
        $this->assertEqualsCanonicalizing(
            ['شاخه', 'کلاف'],
            collect($groups['type']['attributes'])->pluck(Attribute::DISPLAY_NAME)->all(),
        );

        $this->assertSame('گرید', $groups['grade'][AttributeType::DISPLAY_NAME]);
        $this->assertSame(
            ['A1'],
            collect($groups['grade']['attributes'])->pluck(Attribute::DISPLAY_NAME)->all(),
            'فقط گریدهایی باید بیایند که این محصول واقعاً واریانتشان را دارد.'
        );

        // هر گزینه باید ردیفِ مقدار داشته باشد، وگرنه اصلاً به واریانت وصل نمی‌شود.
        foreach ($groups['size']['attributes'] as $option) {
            $this->assertNotEmpty($option['values'], "گزینه‌ی سایز {$option['name']} ردیفِ مقدار ندارد.");
        }
    }

    #[Test]
    public function filtering_by_an_option_narrows_the_variant_list(): void
    {
        $product = $this->demoProduct('rebar-plain-a1');

        $coil = Attribute::query()
            ->whereHas('attributeType', fn ($q) => $q->where(AttributeType::NAME, 'type'))
            ->where(Attribute::NAME, 'کلاف')
            ->firstOrFail();

        $skus = collect(
            $this->getJson("/api/products/{$product->cuid}/variants?filter_categories[]={$coil->id}")
                ->assertOk()
                ->json('data.data')
        )->pluck('sku');

        // اگر گزینه‌ها یک سطح پایین‌تر (روی `attribute_values`) مدل می‌شدند،
        // این فیلتر هر سه واریانت را برمی‌گرداند — یعنی عملاً بی‌اثر بود.
        $this->assertEqualsCanonicalizing(
            ['DEMO-RBP-A1-08', 'DEMO-RBP-A1-10'],
            $skus->all(),
        );
    }

    #[Test]
    public function re_running_the_seeder_does_not_duplicate_anything(): void
    {
        $before = DB::table('product_variant_attributes')->count();

        $this->seed(StagingCatalogSeeder::class);

        $this->assertSame($before, DB::table('product_variant_attributes')->count());
    }
}

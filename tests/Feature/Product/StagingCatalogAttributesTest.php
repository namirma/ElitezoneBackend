<?php

namespace Tests\Feature\Product;

use App\Models\Attribute;
use App\Models\AttributeValue;
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
        // هر دو سیدر: کاتالوگِ نمایشی (`DEMO-*`) و میلگردِ پایه‌ی `ProductSeeder`
        // (`RBR-*`). دومی روی استیجینگ صفر ویژگی داشت و سایدبارش خالی بود.
        $variants = ProductVariant::query()
            ->where(ProductVariant::SKU, 'like', 'DEMO-%')
            ->orWhere(ProductVariant::SKU, 'like', 'RBR-%')
            ->get();

        $this->assertNotEmpty($variants, 'سیدرِ کاتالوگ هیچ واریانتی نساخت.');

        foreach ($variants as $variant) {
            $this->assertNotEmpty(
                $variant->variantAttributes,
                "واریانت {$variant->sku} هیچ ویژگی‌ای ندارد — سایدبارش خالی می‌ماند."
            );
        }
    }

    #[Test]
    public function the_base_rebar_product_gets_the_same_group_shape(): void
    {
        // `ProductSeeder` ویژگی‌ها را از سطحِ `attributes` می‌گرفت، پس محصولِ
        // «میلگرد آجدار» یا سایدبارِ خالی داشت یا هر گزینه را یک گروه نشان
        // می‌داد. حالا از همان واژگانِ مشترک می‌آید.
        $product = $this->demoProduct('rebar');

        $groups = collect(
            $this->getJson("/api/products/{$product->cuid}/filters")->assertOk()->json('data')
        )->flatMap(fn (array $type) => $type['attributes'] ?? [])
            ->keyBy(Attribute::DISPLAY_NAME);

        $this->assertFalse($groups->has('شاخه'), 'گزینه نباید خودش یک گروهِ سایدبار باشد.');

        $this->assertEqualsCanonicalizing(
            ['شاخه', 'کلاف'],
            collect($groups['نوع محصول']['values'])->pluck('display_value')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['A1', 'A2', 'A3'],
            collect($groups['گرید']['values'])->pluck('display_value')->all(),
        );
    }

    #[Test]
    public function the_sidebar_gets_one_group_per_property_not_one_group_per_option(): void
    {
        $product = $this->demoProduct('rebar-plain-a1');

        $types = $this->getJson("/api/products/{$product->cuid}/filters")
            ->assertOk()
            ->json('data');

        // سایدبار `attributes[]` را سرتیتر و `values[]` را گزینه می‌خواند.
        $groups = collect($types)
            ->flatMap(fn (array $type) => $type['attributes'] ?? [])
            ->keyBy(Attribute::DISPLAY_NAME);

        // ✗ رگرسیونی که این تست می‌گیرد: وقتی گزینه‌ها روی سطحِ `attributes`
        // ساخته شوند، سایدبار به‌جای گروهِ «نوع محصول» دو گروهِ «شاخه» و «کلاف»
        // نشان می‌دهد که هرکدام فقط خودشان را دارند.
        $this->assertFalse($groups->has('شاخه'), 'گزینه نباید خودش یک گروهِ سایدبار باشد.');
        $this->assertFalse($groups->has('کلاف'), 'گزینه نباید خودش یک گروهِ سایدبار باشد.');

        $this->assertTrue($groups->has('نوع محصول'));
        $this->assertEqualsCanonicalizing(
            ['شاخه', 'کلاف'],
            collect($groups['نوع محصول']['values'])->pluck('display_value')->all(),
        );

        // فقط مقادیری که این محصول واقعاً واریانتشان را دارد.
        $this->assertEqualsCanonicalizing(
            ['A1'],
            collect($groups['گرید']['values'])->pluck('display_value')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['8', '10', '12'],
            collect($groups['سایز (قطر)']['values'])->pluck('display_value')->all(),
        );
    }

    #[Test]
    public function filtering_by_an_option_narrows_the_variant_list(): void
    {
        $product = $this->demoProduct('rebar-plain-a1');

        $coil = AttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where(Attribute::NAME, 'نوع محصول'))
            ->where(AttributeValue::VALUE, 'کلاف')
            ->firstOrFail();

        $skus = collect(
            $this->getJson("/api/products/{$product->cuid}/variants?filter_values[]={$coil->id}")
                ->assertOk()
                ->json('data.data')
        )->pluck('sku');

        // با `filter_categories` (id گروه) هر سه واریانت برمی‌گشتند — چون همه
        // «نوع محصول» دارند. تفکیک فقط در سطحِ مقدار ممکن است.
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

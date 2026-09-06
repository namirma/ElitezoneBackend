<?php

namespace Tests\Feature\Product;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\FactoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StagingCatalogSeeder;
use Database\Seeders\UserTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
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
        if (! class_exists(FactoryStock::class)) {
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
        $product = $this->demoProduct('rebar-rolled');

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
        // مقادیر عینِ کاتالوگِ مرجع — سایدبارِ استیجینگ باید همان چیزی را نشان
        // بدهد که سایتِ اصلی نشان می‌دهد، نه زیرمجموعه‌ای از آن.
        $this->assertEqualsCanonicalizing(
            ['A0', 'A2', 'A3', 'A4', 'A5'],
            collect($groups['گرید']['values'])->pluck('display_value')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['65', '80', '100', '120', '140', '160', '180', '200',
                '220', '250', '260', '280', '300', '320', '360', '400'],
            collect($groups['سایز (قطر)']['values'])->pluck('display_value')->all(),
        );
        $this->assertSame(['آجدار'], collect($groups['نوع سطح']['values'])->pluck('display_value')->all());
    }

    #[Test]
    public function filtering_by_an_option_narrows_the_variant_list(): void
    {
        $product = $this->demoProduct('rebar-rolled');

        $coil = AttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where(Attribute::NAME, 'نوع محصول'))
            ->where(AttributeValue::VALUE, 'کلاف')
            ->firstOrFail();

        $skus = collect(
            $this->getJson("/api/products/{$product->cuid}/variants?filter_values[]={$coil->id}")
                ->assertOk()
                ->json('data.data')
        )->pluck('sku');

        // با `filter_categories` (id گروه) همه‌ی واریانت‌ها برمی‌گشتند — چون همه
        // «نوع محصول» دارند. تفکیک فقط در سطحِ مقدار ممکن است.
        $this->assertNotEmpty($skus);
        $this->assertLessThan($this->variantCount($product), $skus->count());
        $this->assertTrue(
            $skus->every(fn (string $sku) => str_contains($sku, 'DEMO-RBR-')),
            'فیلترِ «کلاف» نباید واریانتِ محصولِ دیگری را برگرداند.'
        );
    }

    #[Test]
    public function the_label_form_is_gone_and_no_longer_pretends_to_filter(): void
    {
        // لایه‌ی سازگاریِ برچسبی حذف شد چون **هرگز کار نمی‌کرد**: در کاتالوگِ
        // واقعی `attributes.name` نامِ ماشینیِ انگلیسی است (`size`, `grade`) و
        // سایدبار `display_name` فارسی را می‌فرستاد، پس تطبیق همیشه شکست
        // می‌خورد و کلِ فهرست برمی‌گشت.
        //
        // تستِ قبلی سبز بود چون سیدرِ نمایشی `name` و `display_name` را یکی
        // می‌گذارد — یعنی روی داده‌ای می‌گذشت که شکلِ داده‌ی واقعی را نداشت.
        // این کیس همان اشتباه را برعکس پین می‌کند: پارامترِ ناشناس باید بی‌اثر
        // باشد، نه اینکه وانمود کند فیلتر است.
        $product = $this->demoProduct('rebar-rolled');
        $total = $this->variantCount($product);

        $skus = collect(
            $this->getJson("/api/products/{$product->cuid}/variants?".urlencode('سایز (قطر)').'=100&per_page=100')
                ->assertOk()
                ->json('data.data')
        )->pluck('sku');

        $this->assertCount($total, $skus, 'پارامترِ برچسبی نباید فیلتر کند — قراردادش `filter_values[]` است.');
    }

    #[Test]
    public function two_ticks_in_two_groups_narrow_together_instead_of_widening(): void
    {
        // یک `whereIn` روی همه‌ی idهای انتخاب‌شده یعنی OR: تیکِ دوم نتیجه را
        // **پهن‌تر** می‌کرد نه باریک‌تر. کاربر «گریدِ A3» را می‌زد، بعد «سایز ۱۰۰»
        // را هم می‌زد و ناگهان واریانتِ بیشتری می‌دید — دقیقاً خلافِ کاری که
        // یک فیلتر می‌کند.
        $product = $this->demoProduct('rebar-rolled');

        $grade = $this->valueId('گرید', 'A3');
        $size = $this->valueId('سایز (قطر)', '100');

        $byGrade = $this->skusFor($product, [$grade]);
        $byBoth = $this->skusFor($product, [$grade, $size]);

        $this->assertNotEmpty($byGrade);
        $this->assertLessThanOrEqual(
            $byGrade->count(),
            $byBoth->count(),
            'افزودنِ تیکِ دوم هرگز نباید نتیجه را بزرگ‌تر کند.'
        );

        // و هرچه برگشت باید واقعاً هر دو شرط را داشته باشد.
        foreach ($byBoth as $sku) {
            $this->assertTrue($byGrade->contains($sku));
        }

        // دو تیک در **یک** گروه اما OR می‌ماند: «A3 یا A4».
        $twoGrades = $this->skusFor($product, [$grade, $this->valueId('گرید', 'A4')]);
        $this->assertGreaterThan($byGrade->count(), $twoGrades->count());
    }

    private function valueId(string $group, string $option): int
    {
        return (int) AttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where(Attribute::NAME, $group))
            ->where(AttributeValue::VALUE, $option)
            ->value(AttributeValue::ID);
    }

    /** @param  list<int>  $valueIds */
    private function skusFor(Product $product, array $valueIds): Collection
    {
        $query = collect($valueIds)->map(fn (int $id) => "filter_values[]={$id}")->implode('&');

        return collect(
            $this->getJson("/api/products/{$product->cuid}/variants?{$query}&per_page=100")
                ->assertOk()
                ->json('data.data')
        )->pluck('sku');
    }

    private function variantCount(Product $product): int
    {
        return $product->variants()->count();
    }

    #[Test]
    public function re_running_the_seeder_does_not_duplicate_anything(): void
    {
        $before = DB::table('product_variant_attributes')->count();

        $this->seed(StagingCatalogSeeder::class);

        $this->assertSame($before, DB::table('product_variant_attributes')->count());
    }
}

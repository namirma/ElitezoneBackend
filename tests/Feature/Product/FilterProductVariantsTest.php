<?php

namespace Tests\Feature\Product;

use App\Actions\Product\FilterProductVariantsAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductView;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * نوارِ بالای صفحه‌ی دسته‌بندی «موجودی کالا» و «پرفروش‌ترین» را می‌فرستاد و
 * سرویس هر دو را بی‌صدا دور می‌ریخت — کنترلِ بی‌اثر، خلافِ قاعده‌ی صداقت
 * (اسپکِ ۱۴۰۵/۰۶/۲۴، بند ۱.۲). این تست هر دو را پین می‌کند.
 */
class FilterProductVariantsTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    private ProductVariant $inStock;

    private ProductVariant $soldOut;

    protected function setUp(): void
    {
        parent::setUp();

        $category = ProductCategory::create([
            'name' => 'دسته تست فیلتر',
            'slug' => 'filter-cat-'.Str::random(8),
            'code' => 'FL_'.Str::random(6),
        ]);

        $this->product = Product::create([
            'cuid' => Str::random(20),
            'category_id' => $category->id,
            'name' => 'محصول تست فیلتر',
            'slug' => 'filter-product-'.Str::random(8),
            'sku' => 'SKU_'.Str::random(6),
        ]);

        $this->inStock = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'موجود',
            'sku' => 'VSKU_'.Str::random(6),
            'stock_quantity' => 1500,
        ]);

        $this->soldOut = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'ناموجود',
            'sku' => 'VSKU_'.Str::random(6),
            'stock_quantity' => 0,
        ]);
    }

    private function ids(array $filters): array
    {
        return app(FilterProductVariantsAction::class)
            ->execute($this->product, $filters, 'guest')
            ->getCollection()
            ->pluck('id')
            ->all();
    }

    #[Test]
    public function availability_filters_by_the_stock_rollup(): void
    {
        $this->assertSame([$this->inStock->id], $this->ids(['availability' => 'in_stock']));
        $this->assertSame([$this->soldOut->id], $this->ids(['availability' => 'out_of_stock']));
        $this->assertCount(2, $this->ids(['availability' => 'all']));
        $this->assertCount(2, $this->ids([]));
    }

    #[Test]
    public function best_selling_orders_by_sold_quantity(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::PRODUCT_VARIANT_ID => $this->soldOut->id,
            OrderItem::QUANTITY => 40,
        ]);

        $this->assertSame([$this->soldOut->id, $this->inStock->id], $this->ids(['sort' => 'best_selling']));

        // دو خطِ کوچک که جمعشان از یک خطِ بزرگ بیشتر است — جمعِ مقدار، نه شمارِ ردیف.
        OrderItem::factory()->count(2)->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::PRODUCT_VARIANT_ID => $this->inStock->id,
            OrderItem::QUANTITY => 25,
        ]);

        $this->assertSame([$this->inStock->id, $this->soldOut->id], $this->ids(['sort' => 'best_selling']));
    }

    #[Test]
    public function most_viewed_orders_by_the_variant_view_count_written_by_the_view_endpoint(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'];

        // صفِ تست sync است، پس شمارنده بلافاصله بالا می‌رود.
        $this->postJson("/api/products/variants/{$this->soldOut->id}/view", [], $ua)->assertOk();
        // همان بازدیدکننده در همان روز: یک‌بار شمرده می‌شود.
        $this->postJson("/api/products/variants/{$this->soldOut->id}/view", [], $ua)->assertOk();

        $this->assertSame(1, $this->soldOut->fresh()->view_count);
        $this->assertSame(1, ProductView::where(ProductView::PRODUCT_VARIANT_ID, $this->soldOut->id)->count());
        // بازدیدِ واریانت، محصولِ والد را هم می‌شمارد (آنالیتیکسِ محصول دست‌نخورده می‌ماند).
        $this->assertSame(1, $this->product->fresh()->view_count);

        $this->assertSame([$this->soldOut->id, $this->inStock->id], $this->ids(['sort' => 'most_viewed']));

        $this->inStock->forceFill([ProductVariant::VIEW_COUNT => 5])->save();
        $this->assertSame([$this->inStock->id, $this->soldOut->id], $this->ids(['sort' => 'most_viewed']));
    }

    #[Test]
    public function the_request_rejects_an_unknown_availability_and_accepts_every_topbar_option(): void
    {
        $this->getJson("/api/products/{$this->product->cuid}/variants?availability=maybe")
            ->assertUnprocessable();
        foreach (['newest', 'cheapest', 'most_expensive', 'most_viewed', 'best_selling'] as $sort) {
            $this->getJson("/api/products/{$this->product->cuid}/variants?availability=in_stock&sort={$sort}")
                ->assertOk();
        }
    }
}

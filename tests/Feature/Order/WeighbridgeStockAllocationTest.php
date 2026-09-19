<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * اصلاحِ موجودی پس از باسکول باید بین **همه‌ی** ردیف‌ها تقسیم شود.
 *
 * باسکول یک عددِ واحد می‌دهد (وزنِ واقعیِ کلِ بار). تا پیش از این کلِ اختلاف روی
 * `items->first()` اعمال می‌شد: سفارشِ دو ردیفی با ۲۰۰ کیلو کسری، هر ۲۰۰ کیلو را
 * از واریانتِ اول کم می‌کرد و دومی دست‌نخورده می‌ماند — یعنی **موجودیِ هر دو غلط**.
 *
 * گاردِ `assertSingleSupplier` این را نمی‌گیرد: تعدادِ *تأمین‌کننده* را می‌شمارد نه
 * تعدادِ *ردیف*، و سبدی با دو کالا از یک کارخانه کاملاً عادی است.
 */
class WeighbridgeStockAllocationTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = app(InventoryService::class);
    }

    /**
     * سفارشی با ردیف‌های (واریانت، وزن) می‌سازد.
     *
     * @param  array<int, array{variant: ProductVariant, weight: float}>  $lines
     */
    private function orderWith(array $lines): Order
    {
        $order = Order::factory()->create();

        foreach ($lines as $line) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_variant_id' => $line['variant']->id,
                'weight' => $line['weight'],
            ]);
        }

        return $order->fresh();
    }

    private function variantWithStock(float $stock): ProductVariant
    {
        return $this->makeVariant($this->makeProduct(), ['stock_quantity' => $stock]);
    }

    #[Test]
    public function a_shortfall_is_split_across_lines_by_weight(): void
    {
        // کسریِ ۳۰۰ کیلو روی سفارشی که ۶۰٪ ردیف اول و ۴۰٪ ردیف دوم است
        // → ۱۸۰ کیلو به اولی، ۱۲۰ کیلو به دومی برمی‌گردد.
        $a = $this->variantWithStock(1000);
        $b = $this->variantWithStock(1000);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 6000],
            ['variant' => $b, 'weight' => 4000],
        ]);

        // وزنِ واقعی کمتر از سفارش → اختلاف منفی → موجودی برمی‌گردد.
        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -300);

        $this->assertEqualsWithDelta(1180, (float) $a->fresh()->stock_quantity, 0.001);
        $this->assertEqualsWithDelta(1120, (float) $b->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function the_second_line_is_no_longer_ignored(): void
    {
        // پین‌کردنِ دقیقِ رفتارِ قدیمی: قبلاً کلِ ۳۰۰ به اولی می‌رفت و دومی
        // دست‌نخورده می‌ماند.
        $a = $this->variantWithStock(1000);
        $b = $this->variantWithStock(1000);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 5000],
            ['variant' => $b, 'weight' => 5000],
        ]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -300);

        $this->assertNotEqualsWithDelta(1300, (float) $a->fresh()->stock_quantity, 0.001,
            'کلِ اختلاف نباید روی ردیفِ اول بنشیند.');
        $this->assertEqualsWithDelta(1150, (float) $a->fresh()->stock_quantity, 0.001);
        $this->assertEqualsWithDelta(1150, (float) $b->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function over_delivery_deducts_instead_of_returning(): void
    {
        $a = $this->variantWithStock(1000);
        $b = $this->variantWithStock(1000);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 5000],
            ['variant' => $b, 'weight' => 5000],
        ]);

        // وزنِ واقعی بیشتر از سفارش → اختلاف مثبت → موجودی کم می‌شود.
        $this->inventory->updateStockAfterWeighbridgeForOrder($order, 100);

        $this->assertEqualsWithDelta(950, (float) $a->fresh()->stock_quantity, 0.001);
        $this->assertEqualsWithDelta(950, (float) $b->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function the_shares_sum_exactly_to_the_delta_even_when_they_do_not_divide_evenly(): void
    {
        // ۱۰۰ کیلو روی سه ردیفِ برابر = ۳۳٫۳۳۳… — آخرین سهم باقیمانده را جذب
        // می‌کند تا مجموع دقیقاً ۱۰۰ بماند و خطای گردکردن انباشته نشود.
        $a = $this->variantWithStock(1000);
        $b = $this->variantWithStock(1000);
        $c = $this->variantWithStock(1000);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 1000],
            ['variant' => $b, 'weight' => 1000],
            ['variant' => $c, 'weight' => 1000],
        ]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -100);

        $returned = (float) $a->fresh()->stock_quantity
            + (float) $b->fresh()->stock_quantity
            + (float) $c->fresh()->stock_quantity
            - 3000;

        $this->assertEqualsWithDelta(100, $returned, 0.001, 'مجموع سهم‌ها باید دقیقاً برابر اختلاف باشد.');
    }

    #[Test]
    public function two_lines_of_the_same_variant_are_aggregated(): void
    {
        // یک واریانت در دو ردیف → یک بار قفل و به‌روزرسانی، با سهمِ جمع‌شده.
        $a = $this->variantWithStock(1000);
        $b = $this->variantWithStock(1000);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 3000],
            ['variant' => $a, 'weight' => 3000],
            ['variant' => $b, 'weight' => 4000],
        ]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -100);

        // واریانت a در مجموع ۶۰٪ وزن دارد → ۶۰ کیلو.
        $this->assertEqualsWithDelta(1060, (float) $a->fresh()->stock_quantity, 0.001);
        $this->assertEqualsWithDelta(1040, (float) $b->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function a_single_line_order_behaves_exactly_as_before(): void
    {
        $a = $this->variantWithStock(1000);

        $order = $this->orderWith([['variant' => $a, 'weight' => 10000]]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -250);

        $this->assertEqualsWithDelta(1250, (float) $a->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function a_negligible_delta_changes_nothing(): void
    {
        $a = $this->variantWithStock(1000);
        $order = $this->orderWith([['variant' => $a, 'weight' => 10000]]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, 0.0004);

        $this->assertEqualsWithDelta(1000, (float) $a->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function an_order_whose_lines_have_no_weight_is_left_untouched(): void
    {
        // داده‌ی خراب نباید موجودی را جابه‌جا کند (و تقسیم بر صفر هم نشود).
        $a = $this->variantWithStock(1000);
        $order = $this->orderWith([['variant' => $a, 'weight' => 0]]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, -500);

        $this->assertEqualsWithDelta(1000, (float) $a->fresh()->stock_quantity, 0.001);
    }

    #[Test]
    public function stock_never_goes_negative(): void
    {
        // کفِ صفرِ updateStockAfterWeighbridge باید پس از سهم‌بندی هم برقرار بماند.
        $a = $this->variantWithStock(50);
        $b = $this->variantWithStock(50);

        $order = $this->orderWith([
            ['variant' => $a, 'weight' => 5000],
            ['variant' => $b, 'weight' => 5000],
        ]);

        $this->inventory->updateStockAfterWeighbridgeForOrder($order, 400);

        $this->assertGreaterThanOrEqual(0, (float) $a->fresh()->stock_quantity);
        $this->assertGreaterThanOrEqual(0, (float) $b->fresh()->stock_quantity);
    }
}

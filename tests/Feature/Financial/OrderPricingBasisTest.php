<?php

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Financial\LedgerReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مبنای قیمتِ سفارش باید کلِ کالا را نمایندگی کند، نه ردیفِ اول را.
 *
 * تسویه با `official_price × weight_before_scale` کار می‌کند. تا پیش از این هیچ‌کس
 * `official_price` را نمی‌نوشت، پس هر سه مسیرِ تسویه روی فالبکِ
 * `items->first()->unit_price` می‌افتادند — یعنی **کلِ تناژِ سفارش به قیمتِ ردیفِ
 * اول** حساب می‌شد.
 *
 * گاردِ `assertSingleSupplier` این را نمی‌گرفت: فقط تعدادِ *تأمین‌کننده* را می‌سنجد،
 * نه تعدادِ *ردیف*. سبدی با دو سایز میلگرد از یک کارخانه کاملاً عادی است و از گارد
 * رد می‌شود — و دقیقاً همان‌جا خریدار اشتباه صورتحساب می‌گرفت.
 */
class OrderPricingBasisTest extends TestCase
{
    use DatabaseTransactions;

    private LedgerReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = app(LedgerReconciler::class);
    }

    /**
     * سفارشی با ردیف‌های داده‌شده می‌سازد.
     *
     * @param  array<int, array{weight: float, unit_price: float}>  $lines
     */
    private function orderWithLines(array $lines, ?float $officialPrice = null): Order
    {
        $order = Order::factory()->create();

        $totalWeight = 0.0;
        foreach ($lines as $line) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'weight' => $line['weight'],
                'unit_price' => $line['unit_price'],
                'total_price' => $line['weight'] * $line['unit_price'],
            ]);
            $totalWeight += $line['weight'];
        }

        $snapshot = [Order::WEIGHT_BEFORE_SCALE => $totalWeight];
        if ($officialPrice !== null) {
            $snapshot[Order::OFFICIAL_PRICE] = $officialPrice;
        }
        $order->forceFill($snapshot)->save();

        return $order->fresh();
    }

    #[Test]
    public function the_weighted_average_reproduces_the_true_goods_value(): void
    {
        // ۵ تن @ ۳۰۰٬۰۰۰ + ۵ تن @ ۳۲۰٬۰۰۰ = ۳٬۱۰۰٬۰۰۰٬۰۰۰
        // میانگین وزنی = ۳۱۰٬۰۰۰ → ۳۱۰٬۰۰۰ × ۱۰٬۰۰۰ = همان ۳٬۱۰۰٬۰۰۰٬۰۰۰
        $order = $this->orderWithLines(
            [['weight' => 5000, 'unit_price' => 300_000], ['weight' => 5000, 'unit_price' => 320_000]],
            officialPrice: 310_000,
        );

        $this->assertSame([], $this->reconciler->mispricedOrders($order->id));
    }

    #[Test]
    public function billing_the_whole_tonnage_at_the_first_lines_price_is_reported(): void
    {
        // دقیقاً همان چیزی که فالبکِ قدیمی تولید می‌کرد: مبنا = قیمتِ ردیفِ اول.
        $order = $this->orderWithLines(
            [['weight' => 5000, 'unit_price' => 300_000], ['weight' => 5000, 'unit_price' => 320_000]],
            officialPrice: 300_000,
        );

        $found = $this->reconciler->mispricedOrders($order->id);

        $this->assertCount(1, $found);
        $this->assertSame(3_100_000_000, (int) $found[0]['goods_value']);
        $this->assertSame(3_000_000_000, (int) $found[0]['charged_basis']);
        $this->assertSame(-100_000_000, (int) $found[0]['difference']);
    }

    #[Test]
    public function an_order_with_no_snapshot_is_measured_against_the_same_fallback_the_code_uses(): void
    {
        // سفارش‌های قبل از این اصلاح `official_price` ندارند. بررسی باید همان
        // فالبکِ کد (قیمتِ ردیفِ اول) را بازتولید کند، وگرنه دقیقاً سفارش‌هایی که
        // آسیب دیده‌اند از دید پنهان می‌مانند.
        $order = $this->orderWithLines(
            [['weight' => 5000, 'unit_price' => 300_000], ['weight' => 5000, 'unit_price' => 320_000]],
            officialPrice: null,
        );

        $found = $this->reconciler->mispricedOrders($order->id);

        $this->assertCount(1, $found);
        $this->assertFalse($found[0]['has_snapshot']);
        $this->assertSame(-100_000_000, (int) $found[0]['difference']);
    }

    #[Test]
    public function a_single_line_order_is_unaffected(): void
    {
        // میانگینِ وزنیِ یک ردیف دقیقاً همان unit_price است — رفتار سفارش‌های
        // تک‌ردیفی نباید ذره‌ای عوض شود.
        $order = $this->orderWithLines(
            [['weight' => 8000, 'unit_price' => 275_000]],
            officialPrice: 275_000,
        );

        $this->assertSame([], $this->reconciler->mispricedOrders($order->id));
    }

    #[Test]
    public function rounding_noise_stays_below_the_tolerance(): void
    {
        // میانگینِ وزنی به دو رقم اعشار گرد می‌شود، پس OP × وزن چند ریال با
        // Σ total_price فرق می‌کند. این خطا نیست و نباید گزارش شود.
        $lines = [['weight' => 3333, 'unit_price' => 100_000], ['weight' => 6667, 'unit_price' => 110_000]];
        $goods = 3333 * 100_000 + 6667 * 110_000;
        $op = round($goods / 10_000, 2);

        $order = $this->orderWithLines($lines, officialPrice: $op);

        $this->assertSame([], $this->reconciler->mispricedOrders($order->id));
    }

    #[Test]
    public function whole_rial_rounding_on_a_heavy_order_is_not_reported(): void
    {
        // `orders.official_price` از مهاجرتِ ریالِ صحیح `decimal(20,0)` است و
        // `PlaceOrderAction` هم از `5e3306c` همان را گرد می‌کند. این گردکردنِ
        // زیرِ یک ریال **در هر کیلو** است، پس روی ۱۰ تن تا ۵٬۰۰۰ ریال اختلاف
        // می‌سازد — یعنی بالاتر از آستانه‌ی ثابتِ ۱٬۰۰۰ ریال، بی‌آنکه مبنای
        // قیمت غلط باشد. آستانه باید سهمِ گردکردن را بشناسد وگرنه هر سفارشِ
        // چندردیفیِ سنگین «بدقیمت» گزارش می‌شود و هشدار بی‌معنی می‌شود.
        $lines = [['weight' => 3333, 'unit_price' => 100_001], ['weight' => 6667, 'unit_price' => 110_003]];
        $goods = 3333 * 100_001 + 6667 * 110_003;

        $order = $this->orderWithLines($lines, officialPrice: round($goods / 10_000));

        $this->assertSame([], $this->reconciler->mispricedOrders($order->id));
    }

    #[Test]
    public function an_order_with_no_weight_is_skipped_rather_than_divided_by_zero(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'weight' => 0,
            'unit_price' => 100_000,
            'total_price' => 0,
        ]);

        $this->assertSame([], $this->reconciler->mispricedOrders($order->id));
    }

    #[Test]
    public function the_full_report_surfaces_it(): void
    {
        $order = $this->orderWithLines(
            [['weight' => 5000, 'unit_price' => 300_000], ['weight' => 5000, 'unit_price' => 320_000]],
            officialPrice: 300_000,
        );

        $report = $this->reconciler->report(orderId: $order->id);

        $this->assertCount(1, $report['mispriced_orders']);
        $this->assertFalse($report['healthy']);
        $this->assertNotSame([], $report['problems']);
    }
}

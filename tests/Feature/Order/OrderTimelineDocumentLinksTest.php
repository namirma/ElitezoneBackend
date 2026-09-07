<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Shipment;
use App\Support\OrderDocumentCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هر لینکِ سندی که **تایم‌لاین** تولید می‌کند، باید سندی باشد که کاتالوگ سرو می‌کند
 * و بیننده‌اش اجازه‌ی بازکردنش را داشته باشد.
 *
 * چرا این تست وجود دارد: `OrderDocumentAccessTest` کلِ کاتالوگ را جارو می‌کند ولی از
 * سمتِ **کنترلر** — یعنی «هر اسلاگِ کاتالوگ به مخاطبِ درستش می‌رسد». هیچ‌چیز از سمتِ
 * دیگر نگاه نمی‌کرد: «تایم‌لاین چه اسلاگی می‌فرستد». و `EmittedUrlsMatchSpaRoutesTest`
 * لینک‌های `/api/` را عمداً رد می‌کند. پس سه اسلاگِ ساختگی — `tax_buyer`،
 * `exit_permit`، `loading_order` — که هیچ‌کدام در کاتالوگ نیستند، سبز شیپ شدند و
 * روی روت ۴۰۴ می‌دادند؛ و الیت‌یار لینکِ `initial` می‌گرفت که مخاطبش فقط خریدار است،
 * یعنی ۴۰۳ روی سندِ خودش.
 *
 * ترکیبِ این تست با `OrderDocumentAccessTest` تضمینِ سرتاسری می‌دهد: این‌جا ثابت
 * می‌شود اسلاگِ منتشرشده در کاتالوگ هست و مخاطبش درست است، آن‌جا ثابت می‌شود همان
 * جفت روی HTTP واقعاً ۲۰۰ می‌گیرد.
 *
 * @see \Tests\Feature\Order\OrderDocumentAccessTest
 * @see \Tests\Feature\Navigation\EmittedUrlsMatchSpaRoutesTest
 */
class OrderTimelineDocumentLinksTest extends TestCase
{
    use DatabaseTransactions;

    /** هر تایم‌لاین برای چه مخاطبی رندر می‌شود. */
    private const TIMELINES = [
        'dashboard_progress' => OrderDocumentCatalog::AUDIENCE_BUYER,
        'supplier_progress' => OrderDocumentCatalog::AUDIENCE_SUPPLIER,
        'elite_yar_progress' => OrderDocumentCatalog::AUDIENCE_ELITE_YAR,
    ];

    /** @param  array<string, mixed>  $snapshot */
    private function orderInState(string $status, array $snapshot = []): Order
    {
        $order = Order::factory()->create([Order::STATUS => $status]);

        if ($snapshot !== []) {
            // ستون‌های اسنپ‌شاتِ مالی fillable نیستند.
            $order->forceFill($snapshot)->save();
        }

        return $order->fresh();
    }

    /**
     * وضعیت‌هایی که با هم هر مرحله‌ی هر سه تایم‌لاین را روشن می‌کنند.
     *
     * یک تستِ sweep که روی یک حالت اجرا شود sweep نیست: مرحله‌ی «مودیان» تا وقتی
     * ستونِ ارجاعش خالی است inactive می‌ماند و اکشنش اصلاً ساخته نمی‌شود.
     *
     * @return array<string, Order>
     */
    private function fixtures(): array
    {
        return [
            'reserved' => $this->orderInState(Order::STATUS_RESERVED, [
                Order::PAYMENT_TO_RESERVE => 20_000_000,
            ]),
            'processing' => $this->orderInState(Order::STATUS_PROCESSING),
            'awaiting_weight_correction' => $this->orderInState(Order::STATUS_AWAITING_WEIGHT_CORRECTION),
            'shipped' => $this->orderInState(Order::STATUS_SHIPPED),
            'partially_delivered' => $this->orderInState(Order::STATUS_PARTIALLY_DELIVERED),
            'delivered' => $this->orderInState(Order::STATUS_DELIVERED),
            'returned_to_origin' => $this->orderInState(Order::STATUS_RETURNED_TO_ORIGIN),
            'cancelled' => $this->orderInState(Order::STATUS_CANCELLED, [
                Order::PAYMENT_TO_RESERVE => 20_000_000,
            ]),
            'every document issued' => $this->orderInState(Order::STATUS_DELIVERED, [
                Order::PAYMENT_TO_RESERVE => 20_000_000,
                Order::SYSTEM_MOADIAN_REF => 'SYS-TAX-1',
                Order::SUPPLIER_MOADIAN_REF => 'SUP-TAX-1',
                Order::ELITE_YAR_MOADIAN_REF => 'EY-TAX-1',
                Order::ELITE_YAR_HOLD_RELEASED_AT => now(),
            ]),
        ];
    }

    #[Test]
    public function every_document_link_the_timeline_emits_is_one_its_viewer_may_open(): void
    {
        $failures = [];
        $seen = [];

        foreach ($this->fixtures() as $fixture => $order) {
            foreach (self::TIMELINES as $accessor => $audience) {
                foreach ($order->{$accessor}['steps'] as $step) {
                    foreach ($step['actions'] as $action) {
                        $link = $action['link'];

                        if (! str_contains($link, '/invoice/')) {
                            continue;
                        }

                        $where = "{$accessor} / {$fixture} / مرحله‌ی «{$step['key']}»";

                        if (preg_match('#^/api/profile/orders/\d+/invoice/([^/?]+)$#', $link, $m) !== 1) {
                            $failures[] = "{$where}: «{$link}» شکلِ لینکِ دانلودِ سند را ندارد.";

                            continue;
                        }

                        $slug = $m[1];
                        $seen[] = "{$audience}:{$slug}";

                        if (! in_array($slug, OrderDocumentCatalog::downloadableSlugs(), true)) {
                            // همان سه اسلاگِ ساختگی: روت با آن‌ها نمی‌خواند، پس ۴۰۴.
                            $failures[] = "{$where}: اسلاگِ «{$slug}» در کاتالوگ نیست — روت ۴۰۴ می‌دهد.";

                            continue;
                        }

                        if (! in_array($audience, OrderDocumentCatalog::audiencesFor($slug), true)) {
                            $allowed = implode('، ', OrderDocumentCatalog::audiencesFor($slug));
                            $failures[] = "{$where}: «{$slug}» مالِ [{$allowed}] است، نه «{$audience}» — ۴۰۳.";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $failures, "لینک‌های سندِ تایم‌لاین با کاتالوگ نمی‌خوانند:\n".implode("\n", $failures));

        // بدونِ این، خالی‌شدنِ فیکسچر دوباره تست را بی‌صدا سبز می‌کند.
        $this->assertNotEmpty($seen, 'هیچ لینکِ سندی جارو نشد — فیکسچر پوچ است.');

        // الیت‌یار باید اسنادِ **خودش** را بگیرد: Doc D (گزارش الیت یار) و Doc Q
        // (فاکتور مودیانِ خودش). پیش از این هیچ‌کدام لینک نداشتند و به‌جایشان سندِ
        // خریدار لینک می‌شد.
        $this->assertContains(OrderDocumentCatalog::AUDIENCE_ELITE_YAR.':'.OrderDocumentCatalog::SLUG_ELITE_YAR_REPORT, $seen);
        $this->assertContains(OrderDocumentCatalog::AUDIENCE_ELITE_YAR.':'.OrderDocumentCatalog::SLUG_TAX_ELITE_YAR, $seen);
    }

    /**
     * سندِ مالیاتی (Doc P) هیچ ربطی به این ندارد که بار کجاست.
     *
     * ادمین آن را روتین درست بعد از باسکول صادر می‌کند، وقتی سفارش هنوز `shipped`
     * است و بار روی کامیون. کدِ قبلی با دیدنِ `system_moadian_ref` مرحله‌ی جاری را
     * می‌پراند به آخر، پس:
     *
     *   • مرحله‌ی «تحویل» برای باری که در راه بود `completed` نشان داده می‌شد، و
     *   • دکمه‌ی «تأیید تحویل» — که به `state === 'current'` گره خورده — دیگر هرگز
     *     رندر نمی‌شد.
     *
     * نتیجه‌ی واقعی: خریدار نمی‌توانست تحویل را تأیید کند، `shipments:expire-delivery`
     * بار را بعد از مهلت برمی‌گرداند، و آن عودت برای تحویلی که **واقعاً انجام شده
     * بود** بدهی روی خریدار می‌ساخت.
     */
    #[Test]
    public function a_tax_document_does_not_move_the_buyers_current_step(): void
    {
        $order = $this->orderInState(Order::STATUS_SHIPPED, [
            Order::SYSTEM_MOADIAN_REF => 'MOADIAN-SYS-TEST',
        ]);

        // محموله‌ای که راننده‌اش رسیده و خریدار هنوز تأییدش نکرده — همان حالتی که
        // دکمه‌ی «تأیید تحویل» برایش وجود دارد.
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $order->id,
            Shipment::WEIGHT => 1000,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_ARRIVED,
        ]);
        $shipment->forceFill([
            Shipment::ARRIVED_AT => now(),
            Shipment::DELIVERY_DEADLINE => now()->addHours(5),
        ])->save();

        $steps = collect($order->fresh()->dashboard_progress['steps'])->keyBy('key');

        $this->assertSame('current', $steps['shipped']['state'],
            'بار در راه است، پس مرحله‌ی جاری باید «در حال حمل» باشد نه چیزِ دیگر.');

        $this->assertSame('inactive', $steps['delivered']['state'],
            'باری که هنوز تحویل نشده نباید «تحویل شد» را completed نشان دهد.');

        $this->assertContains(
            __('orders.actions.confirm_delivery'),
            array_column($steps['shipped']['actions'], 'label'),
            'بدونِ این دکمه خریدار راهی برای تأیید تحویل ندارد و بار با بدهی عودت می‌خورد.'
        );

        // Doc P واقعاً صادر شده، پس دایره‌ی خودش سبز می‌ماند — و لینکِ دانلودش هم
        // باید باقی بماند. «متوقف‌کردنِ پرشِ مرحله» نباید سند را پنهان کند.
        $this->assertSame('completed', $steps['moadian']['state']);
        $this->assertNotEmpty($steps['moadian']['actions']);
    }
}

<?php

namespace Tests\Feature\Order;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Order\OrderTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * روایتِ زمانیِ سفارش.
 *
 * ارزشِ این سرویس در «کنارِ هم چیدن» است، نه در خواندنِ تک‌تکِ جدول‌ها — پس تست‌ها
 * روی همان تمرکز دارند: آیا هر چهار منبع واقعاً می‌آیند، آیا ترتیب درست است، و آیا
 * فاصله‌ی زمانیِ بین رویدادها حساب می‌شود.
 */
class OrderTimelineTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private OrderTimelineService $timeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeline = app(OrderTimelineService::class);
    }

    #[Test]
    public function it_merges_all_four_tracks_into_one_chronological_story(): void
    {
        $order = $this->orderWithFullStory();

        $events = $this->timeline->build($order)['events'];

        $tracks = array_column($events, 'track');
        $this->assertContains('lifecycle', $tracks);
        $this->assertContains('status', $tracks);
        $this->assertContains('money', $tracks);
        $this->assertContains('document', $tracks);

        // ترتیب باید اکیداً غیرنزولی باشد — کلِ فایده‌ی ادغام همین است.
        $times = array_column($events, 'at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
    }

    #[Test]
    public function it_reports_the_gap_between_consecutive_events(): void
    {
        $order = Order::factory()->create([
            Order::CREATED_AT => now()->subDays(3),
            Order::PAID_AT => now()->subDays(3)->addHours(5),
        ]);

        $events = $this->timeline->build($order)['events'];

        // اولین رویداد مرجع است و فاصله ندارد؛ دومی باید ۵ ساعت بعد گزارش شود.
        $this->assertNull($events[0]['gap']);
        $this->assertSame('5 ساعت بعد', $events[1]['gap']);
    }

    #[Test]
    public function it_labels_an_order_with_the_order_status_not_the_fee_status(): void
    {
        // پین‌کردنِ دورزدنِ یک برخوردِ واقعی: `PAYMENT_PENDING` و
        // `FEE_PAYMENT_PENDING` هر دو رشته‌ی 'pending' هستند و در آرایه‌ی مشترکِ
        // قدیمی آخری روی بقیه می‌نشست. اگر روزی کسی این سرویس را به آن آرایه
        // برگرداند، برچسبِ وضعیتِ سفارش با برچسبِ سهم جابه‌جا می‌شود.
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PROCESSING]);

        $summary = $this->timeline->build($order)['order'];

        $this->assertSame('در حال آماده‌سازی', $summary['status_label']);
        $this->assertNotSame('در انتظار پرداخت سهم', $summary['status_label']);
    }

    #[Test]
    public function it_labels_every_order_status_that_the_state_machine_can_reach(): void
    {
        // هر وضعیتی که بدون برچسب بماند، در روایت به‌صورت اسلاگِ خام دیده می‌شود.
        $statuses = [
            Order::STATUS_RESERVED, Order::STATUS_PROCESSING,
            Order::STATUS_AWAITING_WEIGHT_CORRECTION, Order::STATUS_SHIPPED,
            Order::STATUS_PARTIALLY_DELIVERED, Order::STATUS_DELIVERED,
            Order::STATUS_RETURNED_TO_ORIGIN, Order::STATUS_CANCELLED,
        ];

        foreach ($statuses as $status) {
            $this->assertNotSame(
                $status,
                __('orders.order_status.'.$status),
                "وضعیت «{$status}» برچسب فارسی ندارد.",
            );
        }
    }

    #[Test]
    public function a_status_transition_carries_the_admin_who_made_it(): void
    {
        $order = Order::factory()->create();
        $actor = User::factory()->create();

        OrderStatusHistory::create([
            OrderStatusHistory::ORDER_ID => $order->id,
            OrderStatusHistory::FROM_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::TO_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::USER_ID => $actor->id,
            OrderStatusHistory::CREATED_AT => now()->addMinutes(30),
        ]);

        // OrderObserver خودش هنگام ساختِ سفارش یک ردیفِ تاریخچه با user_id تهی ثبت
        // می‌کند، پس اولین رویدادِ «وضعیت» همیشه آن است نه این — گذارِ موردنظر باید
        // با مقصدش پیدا شود.
        $status = collect($this->timeline->build($order)['events'])
            ->last(fn (array $event) => $event['track'] === 'status');

        $this->assertNotNull($status);
        $this->assertSame($actor->full_name, $status['actor']);
        $this->assertStringContainsString('در حال آماده‌سازی', $status['title']);
    }

    #[Test]
    public function a_money_event_names_the_party_and_the_amount(): void
    {
        $order = Order::factory()->create();
        $wallet = Wallet::factory()->create();

        WalletTransaction::factory()->create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::AMOUNT => 7_500_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_WITHDRAW,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_OWNER, 'op' => '-'],
        ]);

        $money = collect($this->timeline->build($order)['events'])->firstWhere('track', 'money');

        $this->assertNotNull($money);
        $this->assertStringContainsString('خریدار', $money['title']);
        $this->assertStringContainsString('بدهکار', $money['title']);
        $this->assertSame('7500000', $money['amount']);
        $this->assertSame(FinancialCodes::WALLET_OWNER, $money['meta']['wallet_code']);
    }

    #[Test]
    public function a_hold_is_visibly_different_from_a_settled_movement(): void
    {
        // تفاوتِ «هولد» و «تسویه‌شده» مهم‌ترین تمایزِ این دامنه است؛ اگر روایت
        // این دو را یکسان نشان دهد، خواننده فکر می‌کند پول رسیده در حالی که نرسیده.
        $order = Order::factory()->create();
        $wallet = Wallet::factory()->create();

        WalletTransaction::factory()->create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::AMOUNT => 1_000_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_HOLD,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_SUPPLIER, 'op' => '+'],
        ]);

        $money = collect($this->timeline->build($order)['events'])->firstWhere('track', 'money');

        $this->assertStringContainsString('هولد', $money['title']);
    }

    #[Test]
    public function a_document_event_shows_its_human_readable_name(): void
    {
        $order = Order::factory()->create();

        Invoice::create([
            'order_id' => $order->id,
            'user_id' => $order->{Order::PLACER_USER_ID},
            'document_code' => FinancialCodes::DOC_BILL_OF_LADING,
            'invoice_number' => 'EZ-TEST-'.uniqid(),
            'amount' => 1_200_000,
        ]);

        $doc = collect($this->timeline->build($order)['events'])->firstWhere('track', 'document');

        $this->assertNotNull($doc);
        $this->assertStringContainsString('بارنامه', $doc['title']);
        $this->assertStringContainsString(FinancialCodes::DOC_BILL_OF_LADING, $doc['title']);
    }

    #[Test]
    public function the_weighbridge_event_spells_out_the_weight_difference(): void
    {
        // اختلافِ وزن همان چیزی است که کلِ تسویه‌ی پس از باسکول رویش می‌چرخد.
        $order = Order::factory()->create();
        $order->forceFill([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::WEIGHT_BEFORE_SCALE => 10_000,
            Order::WEIGHT_AFTER_SCALE => 9_850,
        ])->save();

        $event = collect($this->timeline->build($order)['events'])
            ->firstWhere('title', 'توزین کامل شد');

        $this->assertNotNull($event);
        $this->assertStringContainsString('-150', $event['detail']);
    }

    #[Test]
    public function an_order_with_nothing_recorded_still_returns_a_usable_shape(): void
    {
        $order = Order::factory()->create();

        $story = $this->timeline->build($order);

        // «هیچ رویدادی» در عمل یعنی دو تا: ثبتِ سفارش (created_at) و ردیفِ تاریخچه‌ای
        // که OrderObserver خودکار می‌سازد. هیچ سفارشی روایتِ خالی ندارد.
        $this->assertCount(2, $story['events']);
        $this->assertSame(['lifecycle', 'status'], array_column($story['events'], 'track'));
        $this->assertSame(0, $story['span']['days']);
        $this->assertSame($order->order_number, $story['order']['order_number']);
    }

    #[Test]
    public function the_admin_endpoint_returns_the_story(): void
    {
        $order = $this->orderWithFullStory();

        Sanctum::actingAs($this->makeAdmin());

        $this->getJson("/api/admin/orders/{$order->id}/timeline")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'order' => ['id', 'order_number', 'status_label'],
                    'events' => [['at', 'at_jalali', 'track', 'title', 'gap']],
                    'span' => ['from', 'to', 'days'],
                ],
            ]);
    }

    #[Test]
    public function the_timeline_endpoint_is_not_swallowed_by_the_show_wildcard(): void
    {
        // /{order}/timeline باید پیش از /{order} ثبت شده باشد، وگرنه Laravel
        // «timeline» را به‌عنوان بخشی از پارامتر نمی‌گیرد و روت هرگز اجرا نمی‌شود.
        $order = Order::factory()->create();

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->getJson("/api/admin/orders/{$order->id}/timeline");

        $this->assertArrayHasKey('events', $response->json('data'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator',
        ]);

        $admin = User::factory()->create();
        $admin->addRole($role);
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }

    /** سفارشی که هر چهار مسیرِ روایت را دارد. */
    private function orderWithFullStory(): Order
    {
        $order = Order::factory()->create([
            Order::CREATED_AT => now()->subDays(2),
        ]);

        $order->forceFill([Order::PAID_AT => now()->subDays(2)->addHour()])->save();

        OrderStatusHistory::create([
            OrderStatusHistory::ORDER_ID => $order->id,
            OrderStatusHistory::FROM_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::TO_STATUS => Order::STATUS_PROCESSING,
            OrderStatusHistory::USER_ID => User::factory()->create()->id,
            OrderStatusHistory::CREATED_AT => now()->subDays(2)->addHours(2),
        ]);

        WalletTransaction::factory()->create([
            WalletTransaction::WALLET_ID => Wallet::factory()->create()->id,
            WalletTransaction::ORDER_ID => $order->id,
            WalletTransaction::AMOUNT => 5_000_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_WITHDRAW,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::META => ['code' => FinancialCodes::WALLET_OWNER, 'op' => '-'],
            WalletTransaction::CREATED_AT => now()->subDays(2)->addHours(3),
        ]);

        Invoice::create([
            'order_id' => $order->id,
            'user_id' => $order->{Order::PLACER_USER_ID},
            'document_code' => FinancialCodes::DOC_RECEIPT_OWNER,
            'invoice_number' => 'EZ-TEST-'.uniqid(),
            'amount' => 5_000_000,
            'created_at' => now()->subDays(2)->addHours(4),
        ]);

        return $order->fresh();
    }
}

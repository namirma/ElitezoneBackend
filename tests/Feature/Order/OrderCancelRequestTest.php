<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderCancelRequest;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * لغوِ دوطرفه با ارجاع به بک‌آفیس — پرسش ۴ مدیریت (۲۰۲۶-۰۹-۰۹).
 *
 * «خریدار یا سوپلایر شروع می‌کند، ولی توافق هر دو لازم است. اگر یکی مخالف بود →
 * ارجاع به بک‌آفیس و تصمیم نهایی آن‌جا.» مسیرِ مالی: با تأییدِ بک‌آفیس «پول از
 * کیف پول تأمین‌کننده کسر و به کیف پول خریدار اضافه می‌شود و سفارش لغو می‌گردد.»
 */
class OrderCancelRequestTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private const POO = 1_000_000.0;

    private const PTS = 800_000.0;

    private User $buyer;

    private User $supplier;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(InvoiceGenerator::class)->shouldReceive('generateCancellationDocs')->andReturnNull();

        // کیف پول‌ها در وضعیتِ پس از پرداخت: PTS نزدِ تأمین‌کننده، باقیِ POO نزدِ سازه.
        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => self::POO - self::PTS, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => self::PTS, Wallet::BALANCE_HOLD => 0]);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
        $this->admin->forceFill([User::ADMIN_LEVEL => 1])->save();

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'CXL-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => self::POO,
        ]);
        $this->order->forceFill([
            Order::PAYMENT_OF_OWNER => self::POO,
            Order::PAYMENT_TO_SUPPLIER => self::PTS,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/profile/orders/{$this->order->id}/cancel-request{$suffix}";
    }

    private function balance(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    private function requestAs(User $user, string $reason = 'کالا دیگر لازم نیست، پروژه متوقف شده است.')
    {
        Sanctum::actingAs($user);

        return $this->postJson($this->url(), ['reason' => $reason]);
    }

    // ── ثبت ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_buyer_can_request_and_the_supplier_is_notified(): void
    {
        $this->requestAs($this->buyer)->assertCreated()
            ->assertJsonPath('data.cancel_request.status', 'pending')
            ->assertJsonPath('data.cancel_request.requested_by', 'buyer')
            ->assertJsonPath('data.cancel_request.is_mine', true)
            ->assertJsonPath('data.cancel_request.awaiting_me', false);

        $request = OrderCancelRequest::first();
        // مهلت از لحظه‌ی ثبت: ۴۸ ساعت.
        $this->assertTrue($request->{OrderCancelRequest::RESPOND_UNTIL}->between(now()->addHours(47), now()->addHours(49)));

        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count());
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $this->buyer->id)->count());
        // هیچ پولی جابه‌جا نشده.
        $this->assertSame(0.0, $this->balance($this->buyer));
    }

    #[Test]
    public function the_supplier_can_request_through_the_legacy_panel_route(): void
    {
        Sanctum::actingAs($this->supplier);

        $this->postJson("/api/profile/orders/{$this->order->id}/cancellation-request", [
            'reason' => 'موجودی این کالا تمام شده و تأمینش ممکن نیست.',
        ])->assertCreated()
            ->assertJsonPath('data.cancel_request.requested_by', 'supplier')
            ->assertJsonPath('data.cancel_request.is_mine', true);

        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->buyer->id)->count());
    }

    #[Test]
    public function a_stranger_cannot_request(): void
    {
        $this->requestAs(User::factory()->create())->assertForbidden();
    }

    #[Test]
    public function a_short_reason_is_a_422(): void
    {
        $this->requestAs($this->buyer, 'لغو')->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function only_a_processing_order_before_the_weighbridge_qualifies(): void
    {
        $this->order->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();
        $this->requestAs($this->buyer)->assertStatus(422);

        $this->order->forceFill([Order::WEIGHING_COMPLETED_AT => null, Order::STATUS => Order::STATUS_RESERVED])->save();
        $this->requestAs($this->buyer)->assertStatus(422);
    }

    #[Test]
    public function one_open_request_per_order(): void
    {
        $this->requestAs($this->buyer)->assertCreated();
        $this->requestAs($this->supplier)->assertStatus(409);
    }

    // ── پاسخِ طرفِ مقابل ────────────────────────────────────────────────────

    #[Test]
    public function mutual_agreement_cancels_and_refunds_supplier_to_buyer(): void
    {
        $this->requestAs($this->buyer)->assertCreated();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url('/accept'))->assertOk()
            ->assertJsonPath('data.status_info.key', Order::STATUS_CANCELLED)
            ->assertJsonPath('data.cancel_request.status', 'approved')
            ->assertJsonPath('data.cancel_request.counterparty_decision', 'accepted');

        $this->assertSame(Order::STATUS_CANCELLED, $this->order->fresh()->{Order::STATUS});
        $this->assertSame(Order::PAYMENT_REFUNDED, $this->order->fresh()->{Order::PAYMENT_STATUS});
        // WS(−PTS) → WSSG(+PTS) ؛ WSSG(−POO) → WO(+POO)
        $this->assertSame(self::POO, $this->balance($this->buyer));
        $this->assertSame(0.0, $this->balance($this->supplier));
    }

    #[Test]
    public function a_rejection_needs_a_reason_and_escalates(): void
    {
        $this->requestAs($this->buyer)->assertCreated();

        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url('/reject'), ['reason' => 'نه'])->assertStatus(422);

        $this->postJson($this->url('/reject'), ['reason' => 'بار آماده‌ی بارگیری است و هزینه شده.'])
            ->assertOk()
            ->assertJsonPath('data.cancel_request.status', 'escalated')
            ->assertJsonPath('data.cancel_request.counterparty_decision', 'rejected')
            ->assertJsonPath('data.cancel_request.awaiting_me', false);

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->fresh()->{Order::STATUS});
        $this->assertNotNull(OrderCancelRequest::first()->{OrderCancelRequest::ESCALATED_AT});
        // درخواست‌کننده خبرِ رد و ارجاع را می‌گیرد.
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->buyer->id)->count());
    }

    #[Test]
    public function the_requester_cannot_answer_their_own_request(): void
    {
        $this->requestAs($this->buyer)->assertCreated();

        $this->postJson($this->url('/accept'))->assertForbidden();
        $this->postJson($this->url('/reject'), ['reason' => 'دلیلی که ده نویسه دارد.'])->assertForbidden();
    }

    #[Test]
    public function the_resource_says_it_is_the_counterpartys_turn(): void
    {
        // پیش از هر درخواستی هر دو طرف دکمه دارند؛ بعد از ثبت، تا بسته‌شدنش هیچ‌کدام.
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/profile/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_cancel', true)
            ->assertJsonMissingPath('data.cancel_request');

        $this->requestAs($this->buyer)->assertCreated()
            ->assertJsonPath('data.can_request_cancel', false);

        Sanctum::actingAs($this->supplier);
        $this->getJson("/api/profile/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_cancel', false)
            ->assertJsonPath('data.cancel_request.awaiting_me', true)
            ->assertJsonPath('data.cancel_request.is_mine', false);
    }

    #[Test]
    public function a_weighed_order_offers_no_cancel_button(): void
    {
        $this->order->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/profile/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_cancel', false);
    }

    // ── مهلت ────────────────────────────────────────────────────────────────

    #[Test]
    public function an_unanswered_request_escalates_after_the_deadline_never_moving_money(): void
    {
        $this->requestAs($this->buyer)->assertCreated();
        $request = OrderCancelRequest::first();

        // هنوز مهلت دارد → کرون دست نمی‌زند.
        $this->artisan('orders:escalate-cancel-requests')->assertSuccessful();
        $this->assertSame('pending', $request->fresh()->{OrderCancelRequest::STATUS});

        $request->forceFill([OrderCancelRequest::RESPOND_UNTIL => now()->subMinute()])->save();
        $this->artisan('orders:escalate-cancel-requests')->assertSuccessful();

        $fresh = $request->fresh();
        $this->assertSame('escalated', $fresh->{OrderCancelRequest::STATUS});
        $this->assertNull($fresh->{OrderCancelRequest::COUNTERPARTY_DECISION});
        $this->assertSame(Order::STATUS_PROCESSING, $this->order->fresh()->{Order::STATUS});
        $this->assertSame(0.0, $this->balance($this->buyer));
        // هر دو طرف خبرِ ارجاع می‌گیرند.
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->buyer->id)->count());
        $this->assertSame(2, UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count());
    }

    // ── بک‌آفیس ─────────────────────────────────────────────────────────────

    private function escalated(): OrderCancelRequest
    {
        $this->requestAs($this->buyer)->assertCreated();
        Sanctum::actingAs($this->supplier);
        $this->postJson($this->url('/reject'), ['reason' => 'بار آماده‌ی بارگیری است و هزینه شده.'])->assertOk();

        return OrderCancelRequest::first();
    }

    #[Test]
    public function the_queue_lists_escalated_requests_by_default(): void
    {
        $request = $this->escalated();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/order-cancel-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.status', 'escalated')
            ->assertJsonPath('data.0.order_number', $this->order->order_number)
            ->assertJsonPath('data.0.counterparty_reason', 'بار آماده‌ی بارگیری است و هزینه شده.');

        $this->getJson('/api/admin/order-cancel-requests?status=approved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function admin_approval_cancels_and_moves_the_money(): void
    {
        $request = $this->escalated();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/approve", [
            'reason' => 'تأمین‌کننده هنوز بارگیری نکرده بود؛ درخواست خریدار موجه است.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.admin_decision', 'approved');

        $this->assertSame(Order::STATUS_CANCELLED, $this->order->fresh()->{Order::STATUS});
        $this->assertSame(self::POO, $this->balance($this->buyer));
        $this->assertSame(0.0, $this->balance($this->supplier));
        $this->assertSame($this->admin->id, (int) $request->fresh()->{OrderCancelRequest::ADMIN_ID});
    }

    #[Test]
    public function admin_rejection_keeps_the_order_and_tells_both_sides(): void
    {
        $request = $this->escalated();
        $before = UserNotification::count();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/reject", [
            'reason' => 'بار طبق مدارک بارگیری شده و لغو ممکن نیست.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->fresh()->{Order::STATUS});
        $this->assertSame(0.0, $this->balance($this->buyer));
        $this->assertSame($before + 2, UserNotification::count());

        // تصمیم نهایی است — بارِ دوم ۴۰۹.
        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/approve", [
            'reason' => 'دلیلی که ده نویسه دارد و بیشتر.',
        ])->assertStatus(409);
    }

    #[Test]
    public function the_admin_reason_is_mandatory_and_a_pending_request_is_not_decidable(): void
    {
        $this->requestAs($this->buyer)->assertCreated();
        $request = OrderCancelRequest::first();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        // هنوز نوبتِ طرفِ مقابل است، نه بک‌آفیس.
        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/approve", [
            'reason' => 'دلیلی که ده نویسه دارد و بیشتر.',
        ])->assertStatus(409);

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function after_a_rejected_request_a_new_one_can_be_opened(): void
    {
        $request = $this->escalated();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/order-cancel-requests/{$request->id}/reject", [
            'reason' => 'بار طبق مدارک بارگیری شده و لغو ممکن نیست.',
        ])->assertOk();

        $this->requestAs($this->supplier, 'حالا خودِ ما هم نمی‌توانیم تأمین کنیم.')->assertCreated();
        $this->assertSame(2, OrderCancelRequest::count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * ‏`POST /api/admin/orders/{order}/force-cancel` — بند ۳.۶ و ۷.۵.
 *
 * بند ۳.۶ صریح است: «لغو اجباری ادمین: **فقط پس از تیکت، با ثبت دلیل** ⇒
 * بازگشت کامل وجه + آزادسازی موجودی.» این تنها اقدامی است که سفارشِ
 * پرداخت‌شده را یک‌طرفه برمی‌گرداند، پس هر دو شرط اجباری‌اند نه اختیاری —
 * وگرنه هیچ ردی نمی‌ماند که چرا انجام شده.
 */
class AdminForceCancelTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    private Order $order;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        // ‏`generate()` خصوصی است؛ متدِ عمومی‌ای که `CancelOrderAction` صدا
        // می‌زند `generateCancellationDocs()` است. Blade‌های PDF در محیط تست
        // وجود ندارند، پس بدون این mock لغو با «View not found» می‌شکند.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateCancellationDocs')
            ->andReturnNull();

        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
        $this->grantAllAdminPermissions($this->admin);

        $buyer = User::factory()->create();

        $this->order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'EZ-FC-'.Str::random(8),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ]);

        // ‏`tickets.department_id` مقدار پیش‌فرض ندارد، پس دپارتمان لازم است.
        $department = Department::create([
            'name' => 'پشتیبانی',
            'slug' => 'support-'.uniqid(),
            'is_available_for_ticket' => true,
        ]);

        $this->ticket = Ticket::create([
            Ticket::CODE => 'TK-'.Str::random(8),
            Ticket::USER_ID => $buyer->id,
            Ticket::DEPARTMENT_ID => $department->id,
            Ticket::SUBJECT => 'درخواست لغو سفارش',
            Ticket::MESSAGE => 'کالا دیگر لازم نیست.',
            Ticket::STATUS => Ticket::STATUS_OPEN,
        ]);
    }

    private function url(): string
    {
        return "/api/admin/orders/{$this->order->id}/force-cancel";
    }

    #[Test]
    public function a_reason_and_a_ticket_cancel_the_order(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), [
            'ticket_id' => $this->ticket->id,
            'reason' => 'خریدار طی تیکت درخواست لغو داد و کالا هنوز ارسال نشده بود.',
        ])->assertOk();

        $this->assertSame(Order::STATUS_CANCELLED, $this->order->refresh()->{Order::STATUS});
    }

    #[Test]
    public function the_reason_is_mandatory(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['ticket_id' => $this->ticket->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->refresh()->{Order::STATUS},
            'اعتبارسنجیِ ناموفق نباید سفارش را لمس کند');
    }

    #[Test]
    public function a_one_word_reason_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        // ‏`min:10` هست تا «ok» یا «.» نتواند نقشِ «ثبت دلیل» را بازی کند.
        $this->postJson($this->url(), ['ticket_id' => $this->ticket->id, 'reason' => 'لغو'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function a_ticket_is_mandatory(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticket_id');

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->refresh()->{Order::STATUS});
    }

    #[Test]
    public function a_nonexistent_ticket_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), [
            'ticket_id' => 99_999_999,
            'reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.',
        ])->assertStatus(422)->assertJsonValidationErrors('ticket_id');
    }

    #[Test]
    public function a_non_admin_cannot_force_cancel(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->url(), [
            'ticket_id' => $this->ticket->id,
            'reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.',
        ])->assertForbidden();

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->refresh()->{Order::STATUS});
    }

    /**
     * BE7 — `POST /orders/{order}/status` همان لغوِ اجباری است، پس همان سقفِ سطح ۲.
     * پیش‌تر فقط تیکتِ اجباری جلویش را می‌گرفت؛ کارشناسِ سطح ۳ با تیکت و دلیل لغو می‌کرد.
     */
    #[Test]
    public function an_agent_cannot_cancel_through_the_legacy_status_route(): void
    {
        $this->setAdminLevel($this->admin, User::ADMIN_LEVEL_AGENT);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$this->order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
            'ticket_id' => $this->ticket->id,
            'reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.',
        ])->assertForbidden()->assertJsonPath('code', 'ADMIN_LEVEL_TOO_LOW');

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->refresh()->{Order::STATUS});
    }

    #[Test]
    public function a_manager_still_cancels_through_the_legacy_status_route(): void
    {
        $this->setAdminLevel($this->admin, User::ADMIN_LEVEL_MANAGER);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$this->order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
            'ticket_id' => $this->ticket->id,
            'reason' => 'دلیلی که به‌اندازه‌ی کافی بلند است.',
        ])->assertOk();

        $this->assertSame(Order::STATUS_CANCELLED, $this->order->refresh()->{Order::STATUS});
    }

    #[Test]
    public function the_reason_and_ticket_are_recorded_in_the_activity_log(): void
    {
        Sanctum::actingAs($this->admin);

        $reason = 'خریدار طی تیکت درخواست لغو داد و کالا هنوز ارسال نشده بود.';

        $this->postJson($this->url(), ['ticket_id' => $this->ticket->id, 'reason' => $reason])
            ->assertOk();

        // بدون این رد، لغو اجباری یک اقدامِ برگشت‌ناپذیرِ بی‌امضا می‌شد.
        $activity = $this->order->activities()
            ->where('description', 'admin.order.force_cancelled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($reason, $activity->properties['reason'] ?? null);
        $this->assertSame($this->ticket->id, $activity->properties['ticket_id'] ?? null);
    }
}

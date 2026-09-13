<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Financial\DebtEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ‏`GET /api/profile/debts` — بند ۷.۴.
 *
 * بدهی سه چیز را برای کاربر می‌بندد: ثبت سفارش، درخواست برداشت، و حملِ بارِ
 * اضافه‌بار. پیش از این اندپوینت کاربر فقط پیامِ «به دلیل بدهی معوق...» را
 * می‌دید و راهی نداشت بفهمد چقدر و بابت چه — تنها گزارشِ بدهی مالِ بک‌آفیس بود.
 *
 * مرکزی‌ترین حالت اینجا `another_users_debt_is_never_visible` است: اندپوینت
 * هیچ پارامترِ `user_id` نمی‌گیرد، و اگر روزی بگیرد این تست می‌شکند.
 */
class UserDebtVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private DebtEngine $debtEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->debtEngine = app(DebtEngine::class);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/profile/debts')->assertUnauthorized();
    }

    #[Test]
    public function a_user_with_no_debt_sees_a_zero_summary(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/profile/debts')
            ->assertOk()
            ->assertJsonPath('data.summary.debt_balance', '0')
            ->assertJsonPath('data.summary.is_blocked', false)
            ->assertJsonPath('data.entries', []);
    }

    #[Test]
    public function the_ledger_and_the_balance_agree(): void
    {
        $this->debtEngine->incur($this->user->id, 500_000, DebtEntry::TYPE_OVERWEIGHT, null, 'اضافه‌بار');
        $this->debtEngine->repay($this->user->id, 200_000, null, 'بازپرداخت');

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/profile/debts')->assertOk();

        $response->assertJsonPath('data.summary.debt_balance', '300000');

        // علامتِ ردیف بازپرداخت باید منفی بماند وگرنه کاربر نمی‌تواند دفتر را
        // جمع بزند و به همان مانده برسد.
        $amounts = array_map('floatval', array_column($response->json('data.entries'), 'amount'));

        $this->assertCount(2, $amounts);
        $this->assertEqualsWithDelta(300_000.0, array_sum($amounts), 0.01,
            'جمعِ دفتر باید دقیقاً برابر مانده باشد');
        $this->assertContains(-200_000.0, $amounts, 'ردیف بازپرداخت منفی نوشته می‌شود');
    }

    #[Test]
    public function a_debt_over_the_credit_limit_reports_negative_headroom_and_blocks(): void
    {
        $this->user->forceFill([User::CREDIT_LIMIT => 100_000])->save();
        $this->debtEngine->incur($this->user->id, 250_000, DebtEntry::TYPE_OVERWEIGHT, null, 'اضافه‌بار');

        Sanctum::actingAs($this->user);

        $this->getJson('/api/profile/debts')
            ->assertOk()
            ->assertJsonPath('data.summary.credit_limit', '100000')
            ->assertJsonPath('data.summary.headroom', '-150000')
            ->assertJsonPath('data.summary.is_blocked', true);
    }

    #[Test]
    public function another_users_debt_is_never_visible(): void
    {
        $stranger = User::factory()->create();
        $this->debtEngine->incur($stranger->id, 900_000, DebtEntry::TYPE_OVERWEIGHT, null, 'بدهی دیگری');

        Sanctum::actingAs($this->user);

        // حتی با پاس‌دادنِ صریحِ `user_id` — اندپوینت آن را نمی‌خواند.
        $this->getJson('/api/profile/debts?user_id='.$stranger->id)
            ->assertOk()
            ->assertJsonPath('data.summary.debt_balance', '0')
            ->assertJsonPath('data.entries', []);
    }

    #[Test]
    public function an_entry_carries_its_order_number(): void
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->user->id,
            Order::ORDER_NUMBER => 'EZ-DEBT-001',
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        $this->debtEngine->incur($this->user->id, 40_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->user);

        $this->getJson('/api/profile/debts')
            ->assertOk()
            ->assertJsonPath('data.entries.0.order_number', 'EZ-DEBT-001');
    }

    #[Test]
    public function an_entry_carries_its_order_id_so_the_settle_route_can_be_called(): void
    {
        // روتِ `overweight/settle` شناسه‌ی عددی می‌خواهد (`whereNumber`)، نه شماره‌ی
        // سفارش. بدونِ `order_id` کلاینت باید شماره را بینِ دو اندپوینت تطبیق می‌داد
        // تا به شناسه برسد — شکافِ ۱۶.۱.
        $order = Order::create([
            Order::PLACER_USER_ID => $this->user->id,
            Order::ORDER_NUMBER => 'EZ-DEBT-002',
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        $this->debtEngine->incur($this->user->id, 40_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');
        // تعدیلِ دستیِ بدونِ سفارش: شناسه باید null باشد، نه صفر و نه خطا.
        $this->debtEngine->incur($this->user->id, 10_000, DebtEntry::TYPE_ADJUSTMENT, null, 'تعدیل');

        Sanctum::actingAs($this->user);

        $entries = collect($this->getJson('/api/profile/debts')->assertOk()->json('data.entries'));

        $this->assertSame($order->id, $entries->firstWhere('order_number', 'EZ-DEBT-002')['order_id']);
        $this->assertNull($entries->firstWhere('order_number', null)['order_id']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\RequestOverweightApprovalAction;
use App\Http\Resources\Order\OrderResource;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\DebtEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بلوکِ `overweight_settlement` در `OrderResource` — شکافِ ۱۶.۱.
 *
 * روتِ `POST /profile/orders/{order}/overweight/settle` از قبل بود، ولی هیچ فیلدی
 * به کلاینت نمی‌گفت کدام سفارش بدهیِ بازِ اضافه‌بار دارد؛ پس دکمه‌ی تسویه در سایت
 * ساخته نشد و خریداری که بارش پشتِ بدهی متوقف بود راهی برای آزادکردنش نداشت.
 *
 * مرکزی‌ترین حالت `the_block_and_the_settle_action_never_disagree` است: اگر بلوک
 * بگوید «تسویه کن» و اکشن ۴۲۲ بدهد، یا برعکس، دکمه مرده است — و هیچ‌کدام از دو
 * طرف جداگانه خطایی نشان نمی‌دهند.
 *
 * ⚠️ `Sanctum::actingAs` **همان نمونه‌ی مدل** را نگه می‌دارد. بلوک مانده‌ی بدهی را
 * از کاربرِ احرازشده می‌خواند (برای صفر کوئری)، پس بعد از `incur()` باید نمونه‌ی
 * تازه داده شود — وگرنه `debt_balance` کهنه‌ی صفر خوانده می‌شود و بلوک بی‌صدا
 * غایب است، و تست به دلیلِ اشتباه رد می‌شد.
 */
class OverweightSettlementBlockTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private DebtEngine $debts;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->debts = app(DebtEngine::class);

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id, 'financial.vat_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 10_000_000, Wallet::BALANCE_HOLD => 0]);
    }

    private function weighedOrder(string $status, ?int $payerId = null): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'EZ-OWS-'.Str::random(10),
            Order::STATUS => $status,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        $order->forceFill(array_filter([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::PAYER_USER_ID => $payerId,
        ], fn ($value) => $value !== null))->save();

        return $order;
    }

    /** @return array<string, mixed> */
    private function show(Order $order): array
    {
        $json = $this->getJson("/api/profile/orders/{$order->id}")->assertOk()->json('data');

        return $json['data'] ?? $json;
    }

    #[Test]
    public function a_payer_with_a_held_load_sees_what_to_settle(): void
    {
        $order = $this->weighedOrder(Order::STATUS_AWAITING_WEIGHT_CORRECTION);
        $this->debts->incur($this->buyer->id, 250_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->buyer->fresh());

        $block = $this->show($order)['overweight_settlement'] ?? null;

        $this->assertNotNull($block, 'بدهکارِ باسکول‌شده باید بلوکِ تسویه را ببیند.');
        $this->assertSame('250000', $block['outstanding_amount']);
        $this->assertTrue($block['is_load_held']);
    }

    #[Test]
    public function a_buyer_within_their_credit_limit_owes_but_their_load_is_not_held(): void
    {
        // `chargeOverweight` دو خروجی دارد: خریدارِ درونِ سقفِ اعتبار مستقیم
        // `SHIPPED` می‌شود در حالی که بدهی‌اش ثبت شده. دکمه باید باشد، ولی «بارِ
        // متوقف» نه.
        $order = $this->weighedOrder(Order::STATUS_SHIPPED);
        $this->debts->incur($this->buyer->id, 80_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->buyer->fresh());

        $block = $this->show($order)['overweight_settlement'] ?? null;

        $this->assertNotNull($block);
        $this->assertFalse($block['is_load_held']);
    }

    #[Test]
    public function no_debt_means_no_block_not_a_zero_amount(): void
    {
        $order = $this->weighedOrder(Order::STATUS_SHIPPED);

        Sanctum::actingAs($this->buyer->fresh());

        // قرینه‌ی بلوکِ `overweight`: نیامدن یعنی «چیزی نیست»، نه صفر ریال.
        $this->assertArrayNotHasKey('overweight_settlement', $this->show($order));
    }

    #[Test]
    public function the_placer_does_not_see_it_when_someone_else_pays(): void
    {
        // خرید برای دیگری: مابه‌التفاوت از کیف پولِ پرداخت‌کننده می‌رود، و
        // `assertPayer` در کنترلر فقط او را می‌پذیرد. ثبت‌کننده دکمه‌ای می‌دید که
        // ۴۰۳ می‌گرفت.
        $payer = User::factory()->create();
        $order = $this->weighedOrder(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $payer->id);
        $this->debts->incur($payer->id, 120_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->buyer->fresh());

        $this->assertArrayNotHasKey('overweight_settlement', $this->show($order));
    }

    #[Test]
    public function the_block_and_the_settle_action_never_disagree(): void
    {
        $order = $this->weighedOrder(Order::STATUS_AWAITING_WEIGHT_CORRECTION);
        $this->debts->incur($this->buyer->id, 250_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->buyer->fresh());
        $this->assertArrayHasKey('overweight_settlement', $this->show($order));

        app(RequestOverweightApprovalAction::class)->settleShortfall($order->fresh());

        // بعد از تسویه همان اندپوینت دیگر دکمه‌ای نشان نمی‌دهد.
        Sanctum::actingAs($this->buyer->fresh());
        $this->assertArrayNotHasKey('overweight_settlement', $this->show($order));
    }

    #[Test]
    public function a_list_of_orders_costs_one_debt_query_however_long_it_is(): void
    {
        $debtQueries = 0;
        DB::listen(function ($query) use (&$debtQueries) {
            if (str_contains($query->sql, 'debt_entries')) {
                $debtQueries++;
            }
        });

        $render = function (int $count) use (&$debtQueries): int {
            $orders = collect(range(1, $count))->map(function () {
                $order = $this->weighedOrder(Order::STATUS_AWAITING_WEIGHT_CORRECTION);
                $this->debts->incur($this->buyer->id, 10_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

                return $order;
            });

            Sanctum::actingAs($this->buyer->fresh());
            $debtQueries = 0;

            // درخواستِ تازه برای هر اجرا، همان‌طور که هر درخواستِ HTTP تازه است.
            $rendered = OrderResource::collection($orders)->toArray(Request::create('/api/profile/orders'));

            // هر سفارش بلوکش را گرفته — وگرنه «یک کوئری» ممکن بود از این باشد
            // که اصلاً هیچ‌کدام به کوئری نرسیده‌اند.
            $this->assertCount($count, array_filter($rendered, fn ($row) => isset($row['overweight_settlement'])));

            return $debtQueries;
        };

        $this->assertSame(1, $render(1));
        $this->assertSame(1, $render(4), 'هر سفارشِ بیشتر در فهرست نباید کوئریِ بدهیِ بیشتری بسازد (N+1).');
    }
}

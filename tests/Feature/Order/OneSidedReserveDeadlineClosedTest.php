<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تمدیدِ یک‌طرفه‌ی رزرو توسطِ تأمین‌کننده بسته است — پاسخِ مدیریت به پرسشِ ۳ «ابهاماتِ
 * توسعه» (۲۰۲۶-۰۹-۰۹): «بعد از پایان مهلت اولین رزرو، سوپلایر می‌تواند زمان رزرو را
 * تمدید کند اما خریدار می‌تواند این موضوع را بپذیرد یا آنکه رد کند.»
 *
 * جریانِ دوطرفه (`POST …/extension` → پذیرش/ردِ خریدار) از ۲۰۲۶-۰۹-۱۵ ساخته شده، ولی
 * `PUT …/reserve-deadline` (بندِ ۳.۴ قدیمی) هنوز مهلتِ خریدار را بدونِ اطلاع و موافقتش
 * جلو می‌برد. هیچ دکمه‌ای در پنل صدایش نمی‌زد؛ با یک درخواستِ مستقیم کار می‌کرد.
 * `ExtendReserveDeadlineAction` می‌ماند — پذیرشِ خریدار در جریانِ دوطرفه از همان می‌گذرد.
 */
class OneSidedReserveDeadlineClosedTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    private function reserved(\DateTimeInterface $deadline): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'OSR-'.Str::random(10),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        $order->forceFill([
            Order::RESERVE_DEADLINE => $deadline,
            Order::RESERVE_DEADLINE_ORIGINAL => $deadline,
            Order::RESERVE_GRACE_HOURS => 0,
            Order::PAYMENT_TO_RESERVE => 0,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);

        return $order->fresh();
    }

    #[Test]
    public function the_supplier_cannot_move_the_deadline_without_the_buyer(): void
    {
        $deadline = now()->addHour()->startOfSecond();
        $order = $this->reserved($deadline);

        Sanctum::actingAs($this->supplier);
        $this->putJson("/api/profile/orders/{$order->id}/reserve-deadline", [
            'reserve_deadline' => now()->addHours(5)->toIso8601String(),
        ])->assertStatus(410);

        $this->assertSame(
            $deadline->toIso8601String(),
            $order->fresh()->{Order::RESERVE_DEADLINE}->toIso8601String(),
        );
    }

    #[Test]
    public function the_refusal_points_to_the_two_party_extension(): void
    {
        $order = $this->reserved(now()->addHour());

        Sanctum::actingAs($this->supplier);
        $res = $this->putJson("/api/profile/orders/{$order->id}/reserve-deadline", [
            'reserve_deadline' => now()->addHours(5)->toIso8601String(),
        ])->assertStatus(410);

        $this->assertSame(__('orders.reserve_deadline.one_sided_closed'), $res->json('message'));
    }

    #[Test]
    public function a_stranger_is_still_refused_before_anything_else(): void
    {
        // بستنِ مسیر نباید گاردِ مالکیت را دور بزند: غیرِ تأمین‌کننده‌ی سفارش همان ۴۰۳ را می‌گیرد.
        $order = $this->reserved(now()->addHour());

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/profile/orders/{$order->id}/reserve-deadline", [
            'reserve_deadline' => now()->addHours(5)->toIso8601String(),
        ])->assertForbidden();
    }
}

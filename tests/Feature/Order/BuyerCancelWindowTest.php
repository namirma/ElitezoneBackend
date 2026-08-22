<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پنجره‌ی لغو خریدار روی رزرو — بند ۳.۴ سند.
 *
 * جدولِ اختیارات سند:
 *   لغو پیش از `reserve_deadline_original`                      → خیر
 *   لغو بین `reserve_deadline_original` و `reserve_deadline`    → **بله**
 *
 * ⚠️ این تست یک قابلیتِ مرده را قفل می‌کند. `Order::isInBuyerCancelWindow()`
 * و گاردِ کنترلر از فاز ۵ درست بودند، ولی `CancelOrderAction` بعدشان هر لغوِ
 * غیرکرونی را روی سفارشِ رزرو throw می‌کرد — پس ردیفِ دومِ آن جدول در عمل
 * هیچ‌وقت اجرا نمی‌شد و هیچ تستی هم نبود که بگیردش.
 */
class BuyerCancelWindowTest extends TestCase
{
    use DatabaseTransactions;

    private CancelOrderAction $action;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CancelOrderAction::class);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    private function reservedOrder(?string $original, ?string $current): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'TEST-CW-'.Str::random(12),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        $order->forceFill([
            Order::RESERVE_DEADLINE_ORIGINAL => $original,
            Order::RESERVE_DEADLINE => $current,
            Order::PAYMENT_TO_RESERVE => 0,
        ])->save();

        return $order->fresh();
    }

    #[Test]
    public function the_buyer_can_cancel_inside_the_extension_the_supplier_added(): void
    {
        // مهلت اولیه گذشته، تأمین‌کننده تا فردا تمدید کرده → داخل پنجره.
        $order = $this->reservedOrder(
            original: now()->subHour()->toDateTimeString(),
            current: now()->addDay()->toDateTimeString(),
        );

        $this->assertTrue($order->isInBuyerCancelWindow());

        $cancelled = $this->action->execute($order, 'لغو توسط کاربر');

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->fresh()->{Order::STATUS});
    }

    #[Test]
    public function the_buyer_cannot_cancel_before_the_original_deadline(): void
    {
        // هنوز به مهلت اولیه نرسیده‌ایم — کالا کنار گذاشته شده و لغو مجاز نیست.
        $order = $this->reservedOrder(
            original: now()->addHour()->toDateTimeString(),
            current: now()->addDay()->toDateTimeString(),
        );

        $this->assertFalse($order->isInBuyerCancelWindow());

        $this->expectExceptionMessage(__('orders.reserve_deadline.cancel_window_closed'));
        $this->action->execute($order, 'لغو توسط کاربر');
    }

    #[Test]
    public function without_an_extension_there_is_no_window_at_all(): void
    {
        // تمدیدی رخ نداده: هر دو مهلت یکی‌اند، پس بازه‌ای برای لغو باز نمی‌شود.
        $same = now()->subHour()->toDateTimeString();
        $order = $this->reservedOrder(original: $same, current: $same);

        $this->assertFalse($order->isInBuyerCancelWindow());

        $this->expectExceptionMessage(__('orders.reserve_deadline.cancel_window_closed'));
        $this->action->execute($order, 'لغو توسط کاربر');
    }

    /** کرانِ انقضا و پنل ادمین از پنجره مستثنی‌اند — همان `isSystemCron`. */
    #[Test]
    public function the_expiry_cron_can_still_cancel_outside_the_window(): void
    {
        $order = $this->reservedOrder(
            original: now()->addHour()->toDateTimeString(),
            current: now()->addDay()->toDateTimeString(),
        );

        $cancelled = $this->action->execute($order, 'لغو سیستمی', true, true);

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->fresh()->{Order::STATUS});
    }
}

<?php

namespace Tests\Feature\Order;

use App\Actions\Order\ExtendReserveDeadlineAction;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تمدید مهلت رزرو و پنجره‌ی لغو خریدار — بند ۳.۴.
 */
class ReserveDeadlineTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create();
    }

    private function reserved($deadline, $original = null): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'RSV-'.Str::random(10),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        $order->forceFill([
            Order::RESERVE_DEADLINE => $deadline,
            Order::RESERVE_DEADLINE_ORIGINAL => $original ?? $deadline,
            Order::RESERVE_GRACE_HOURS => 0,
        ])->save();

        return $order->fresh();
    }

    private function action(): ExtendReserveDeadlineAction
    {
        return app(ExtendReserveDeadlineAction::class);
    }

    // ── تمدید ───────────────────────────────────────────────────────────────

    #[Test]
    public function extending_moves_the_current_deadline_forward(): void
    {
        $order = $this->reserved(now()->addHour());
        $new = now()->addHours(5)->startOfSecond();

        $this->action()->execute($order, $new);

        $this->assertSame($new->toIso8601String(), $order->fresh()->{Order::RESERVE_DEADLINE}->toIso8601String());
    }

    #[Test]
    public function extending_never_touches_the_original_deadline(): void
    {
        // پنجره‌ی لغو خریدار از فاصله‌ی همین دو ستون ساخته می‌شود؛ با جابه‌جا
        // شدنِ مبدأ، «چقدر تمدید شده» قابل بازسازی نیست.
        $original = now()->addHour()->startOfSecond();
        $order = $this->reserved($original);

        $this->action()->execute($order, now()->addHours(5));

        $this->assertSame(
            $original->toIso8601String(),
            $order->fresh()->{Order::RESERVE_DEADLINE_ORIGINAL}->toIso8601String(),
        );
    }

    #[Test]
    public function shortening_the_deadline_is_refused(): void
    {
        $order = $this->reserved(now()->addHours(5));

        $this->expectException(BusinessException::class);
        $this->action()->execute($order, now()->addHour());
    }

    #[Test]
    public function an_equal_deadline_is_refused_too(): void
    {
        // «افزایشی» یعنی اکیداً بزرگ‌تر — وگرنه یک no-op به‌عنوان تمدید ثبت می‌شد.
        $deadline = now()->addHours(5)->startOfSecond();
        $order = $this->reserved($deadline);

        $this->expectException(BusinessException::class);
        $this->action()->execute($order, $deadline);
    }

    #[Test]
    public function only_a_reserved_order_can_be_extended(): void
    {
        $order = $this->reserved(now()->addHour());
        $order->forceFill([Order::STATUS => Order::STATUS_PROCESSING])->save();

        $this->expectException(BusinessException::class);
        $this->action()->execute($order->fresh(), now()->addHours(5));
    }

    // ── پنجره‌ی لغو خریدار ──────────────────────────────────────────────────

    #[Test]
    public function a_buyer_cannot_cancel_before_the_original_deadline(): void
    {
        // کالا به درخواستِ خودش کنار گذاشته شده و تأمین‌کننده هزینه‌ی قفلِ
        // موجودی را می‌دهد؛ خروجِ زودهنگام حقِ او نیست.
        $order = $this->reserved(now()->addHours(2));

        $this->assertFalse($order->isInBuyerCancelWindow());
        $this->assertFalse($this->buyer->can('cancel', $order));
    }

    #[Test]
    public function the_window_opens_only_inside_the_supplier_added_extension(): void
    {
        $original = now()->subMinute();
        $order = $this->reserved(now()->addHours(5), original: $original);

        $this->assertTrue($order->isInBuyerCancelWindow());
        $this->assertTrue($this->buyer->can('cancel', $order));
    }

    #[Test]
    public function no_extension_means_no_window_even_after_the_deadline_passes(): void
    {
        // بدون تمدید، `deadline == original` — هیچ بازه‌ای اضافه نشده که
        // خریدار در آن حقِ خروج داشته باشد. کرونِ انقضا سراغش می‌آید.
        $past = now()->subMinute();
        $order = $this->reserved($past, original: $past);

        $this->assertFalse($order->isInBuyerCancelWindow());
    }

    #[Test]
    public function a_non_reserved_order_is_never_in_the_window(): void
    {
        $order = $this->reserved(now()->addHours(5), original: now()->subMinute());
        $order->forceFill([Order::STATUS => Order::STATUS_PROCESSING])->save();

        $this->assertFalse($order->fresh()->isInBuyerCancelWindow());
    }
}

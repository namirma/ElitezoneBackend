<?php

namespace Tests\Feature\Financial;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دو اثرِ جانبیِ کوپن که هیچ‌کدام «تخفیف» نیستند:
 *   M1 — تخفیف نباید گزینهٔ رزرو را از سفارشِ بزرگ بگیرد.
 *   M3 — تولید کدِ اختصاصی نباید روی برخورد بترکد.
 */
class CouponSideEffectsTest extends TestCase
{
    use DatabaseTransactions;

    // ── M1: آستانهٔ رزرو روی مبلغِ پیش از تخفیف ────────────────────────────

    private function orderWorth(float $finalAmount, float $discount): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'THR-'.uniqid(),
            Order::STATUS => Order::STATUS_PENDING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => $finalAmount,
        ]);

        $order->forceFill([Order::COUPON_DISCOUNT_AMOUNT => $discount])->save();

        return $order->fresh();
    }

    #[Test]
    public function the_reservation_basis_ignores_the_coupon_discount(): void
    {
        $threshold = (float) config('financial.reservation_threshold');

        // سفارشی که *پیش از* تخفیف بالای آستانه است ولی بعد از آن زیر آستانه.
        $order = $this->orderWorth(
            finalAmount: $threshold - 1_000_000,
            discount: 5_000_000,
        );

        $this->assertLessThan($threshold, (float) $order->final_amount);
        $this->assertGreaterThan(
            $threshold,
            $order->reservationBasisAmount(),
            'تصمیمِ رزرو باید بر اساس اندازهٔ واقعی سفارش باشد، نه مبلغِ تخفیف‌خورده.',
        );
    }

    #[Test]
    public function an_order_without_a_coupon_uses_its_final_amount_unchanged(): void
    {
        $order = $this->orderWorth(finalAmount: 12_345_000, discount: 0);

        $this->assertSame(12_345_000.0, $order->reservationBasisAmount());
    }

    /**
     * بند ۱۱.۱ — مرحله‌ی «رزرو» با بیعانه‌ی پرداخت‌شده تعیین می‌شود، نه با آستانه.
     *
     * پیش از بازطراحی این تست مبلغ سفارش را با `reservation_threshold` مقایسه
     * می‌کرد. آن آستانه حذف شده: رزرو دیگر یک قاعده‌ی مبلغی نیست، انتخابِ کاربر
     * بر پایه‌ی توانِ پرداختِ خودش است. پس تنها شاهدِ «این سفارش رزرو شد»
     * خودِ `payment_to_reserve` است.
     */
    #[Test]
    public function the_timeline_shows_the_reserve_step_only_when_a_deposit_was_paid(): void
    {
        $reserved = $this->orderWorth(finalAmount: 50_000_000, discount: 5_000_000);
        $reserved->forceFill([Order::PAYMENT_TO_RESERVE => 20_000_000])->save();

        $titles = array_column($reserved->fresh()->dashboard_progress['steps'] ?? [], 'title');
        $this->assertNotEmpty(
            array_filter($titles, fn ($t) => str_contains($t, 'رزرو')),
            'سفارشی که بیعانه پرداخت کرده باید مرحلهٔ رزرو را داشته باشد.',
        );

        $paidOutright = $this->orderWorth(finalAmount: 50_000_000, discount: 5_000_000);

        $titles = array_column($paidOutright->dashboard_progress['steps'] ?? [], 'title');
        $this->assertEmpty(
            array_filter($titles, fn ($t) => str_contains($t, 'رزرو')),
            'سفارشی که یکجا پرداخت شده نباید مرحلهٔ رزرو داشته باشد.',
        );
    }

    // ── M3: تولید کدِ کوپن مقاوم در برابر برخورد ───────────────────────────

    #[Test]
    public function personal_coupon_generation_survives_a_code_collision(): void
    {
        $service = app(CouponService::class);
        $user = User::factory()->create();

        // ۳۰ کوپن با یک پیشوندِ یکسان — تولید باید بدون QueryException ادامه
        // پیدا کند حتی وقتی فضای کد شلوغ می‌شود.
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            $codes[] = $service->createPersonalCoupon($user, 10, 2, 'test-collision')->code;
        }

        $this->assertCount(30, array_unique($codes), 'کدهای تولیدشده باید یکتا باشند.');
        $this->assertSame(30, Coupon::whereIn(Coupon::CODE, $codes)->count());
    }

    #[Test]
    public function a_generated_code_never_collides_with_an_existing_one(): void
    {
        $service = app(CouponService::class);
        $user = User::factory()->create();

        $first = $service->createPersonalCoupon($user, 10, 2, 'test-a');
        $second = $service->createPersonalCoupon($user, 10, 2, 'test-b');

        $this->assertNotSame($first->code, $second->code);
    }
}

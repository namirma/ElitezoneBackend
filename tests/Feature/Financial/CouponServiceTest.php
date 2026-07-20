<?php

namespace Tests\Feature\Financial;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the checkout-coupon contract: validation branches (inactive/expired/
 * used/foreign), discount math (percent capped at payable, fixed capped too),
 * and redeem marking used_at.
 */
class CouponServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CouponService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CouponService::class);
        $this->user = User::factory()->create();
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            Coupon::CODE => 'TST-'.uniqid(),
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => 10,
            Coupon::IS_ACTIVE => true,
        ], $overrides));
    }

    #[Test]
    public function valid_coupon_passes_validation(): void
    {
        $coupon = $this->makeCoupon();

        $this->assertTrue(
            $this->service->validateForCheckout($this->user, $coupon->code)->is($coupon)
        );
    }

    #[Test]
    public function unknown_inactive_expired_used_and_foreign_coupons_are_rejected(): void
    {
        $cases = [
            ['code' => 'DOES-NOT-EXIST', 'coupon' => null],
            ['coupon' => $this->makeCoupon([Coupon::IS_ACTIVE => false])],
            ['coupon' => $this->makeCoupon([Coupon::EXPIRES_AT => now()->subDay()])],
            ['coupon' => $this->makeCoupon([Coupon::USED_AT => now()])],
            ['coupon' => $this->makeCoupon([Coupon::USER_ID => User::factory()->create()->id])],
        ];

        foreach ($cases as $case) {
            $code = $case['code'] ?? $case['coupon']->code;

            try {
                $this->service->validateForCheckout($this->user, $code);
                $this->fail("Coupon [{$code}] should have been rejected.");
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function personal_coupon_is_valid_for_its_owner(): void
    {
        $coupon = $this->makeCoupon([Coupon::USER_ID => $this->user->id]);

        $this->assertTrue(
            $this->service->validateForCheckout($this->user, $coupon->code)->is($coupon)
        );
    }

    #[Test]
    public function discount_math_is_capped_at_the_payable_amount(): void
    {
        $percent = $this->makeCoupon([Coupon::AMOUNT => 10]);
        $this->assertSame(100_000.0, $this->service->calculateDiscount($percent, 1_000_000));

        $fixed = $this->makeCoupon([Coupon::TYPE => Coupon::TYPE_FIXED, Coupon::AMOUNT => 300_000]);
        $this->assertSame(300_000.0, $this->service->calculateDiscount($fixed, 1_000_000));
        // سقف: تخفیف هرگز از مبلغ قابل پرداخت بیشتر نمی‌شود
        $this->assertSame(200_000.0, $this->service->calculateDiscount($fixed, 200_000));
    }

    #[Test]
    public function redeem_marks_used_and_release_reopens_for_unpaid_order(): void
    {
        $coupon = $this->makeCoupon();

        $this->service->redeem($coupon);
        $this->assertNotNull($coupon->fresh()->used_at);

        $order = \App\Models\Order::create([
            \App\Models\Order::PLACER_USER_ID => $this->user->id,
            \App\Models\Order::ORDER_NUMBER => 'TEST-COUPON-'.uniqid(),
            \App\Models\Order::STATUS => \App\Models\Order::STATUS_PENDING,
            \App\Models\Order::PAYMENT_STATUS => \App\Models\Order::PAYMENT_PENDING,
            \App\Models\Order::COUPON_ID => $coupon->id,
            \App\Models\Order::FINAL_AMOUNT => 100_000,
        ]);

        $this->service->releaseForOrder($order);
        $this->assertNull($coupon->fresh()->used_at);
    }
}

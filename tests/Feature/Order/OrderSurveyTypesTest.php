<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * نظرسنجی دو نوعه طبق سند طراحی:
 *   S (buyer)     — از خریدار (placer / beneficiary)
 *   T (elite_yar) — از الیت یار (صاحب کمیسیون)
 *
 * هر نوع فقط یک‌بار per سفارش؛ ثبت یکی دیگری را بلاک نمی‌کند (باگ قبلی).
 */
class OrderSurveyTypesTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $eliteYAR;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->eliteYAR = User::factory()->create();

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::ORDER_NUMBER => 'SRV-'.Str::random(12),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000_000,
        ]);
    }

    public function test_buyer_survey_does_not_block_elite_yar_survey(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 5])
            ->assertOk();

        Sanctum::actingAs($this->eliteYAR);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 4])
            ->assertOk();

        $this->assertSame(2, $this->order->surveys()->count());
        $this->assertSame(1, $this->order->surveys()->where(OrderSurvey::TYPE, OrderSurvey::TYPE_BUYER)->count());
        $this->assertSame(1, $this->order->surveys()->where(OrderSurvey::TYPE, OrderSurvey::TYPE_ELITE_YAR)->count());
    }

    public function test_duplicate_survey_of_the_same_type_is_rejected(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 5])->assertOk();
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 3])->assertStatus(400);

        Sanctum::actingAs($this->eliteYAR);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 4])->assertOk();
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 2])->assertStatus(400);
    }

    public function test_elite_yar_placer_files_the_elite_yar_survey(): void
    {
        // خرید برای دیگری: الیت یار خودش placer است و beneficiary کارفرماست —
        // نظرسنجی الیت یار T است و S برای کارفرمای گیرنده می‌ماند.
        $employer = User::factory()->create();
        $order = Order::create([
            Order::PLACER_USER_ID => $this->eliteYAR->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::BENEFICIARY_USER_ID => $employer->id,
            Order::ORDER_NUMBER => 'SRV-'.Str::random(12),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000_000,
        ]);

        Sanctum::actingAs($this->eliteYAR);
        $this->postJson("/api/profile/orders/{$order->id}/survey", ['rating' => 5])->assertOk();

        Sanctum::actingAs($employer);
        $this->postJson("/api/profile/orders/{$order->id}/survey", ['rating' => 4])->assertOk();

        $this->assertSame(
            OrderSurvey::TYPE_ELITE_YAR,
            $order->surveys()->where(OrderSurvey::USER_ID, $this->eliteYAR->id)->value(OrderSurvey::TYPE)
        );
        $this->assertSame(
            OrderSurvey::TYPE_BUYER,
            $order->surveys()->where(OrderSurvey::USER_ID, $employer->id)->value(OrderSurvey::TYPE)
        );
    }

    public function test_stranger_cannot_submit_a_survey(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_buyer_survey_sets_the_order_survey_flag_but_elite_yar_survey_does_not(): void
    {
        Sanctum::actingAs($this->eliteYAR);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 4])->assertOk();
        $this->assertNotSame(Order::SURVEY_COMPLETED, $this->order->fresh()->{Order::SURVEY_STATUS});

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$this->order->id}/survey", ['rating' => 5])->assertOk();
        $this->assertSame(Order::SURVEY_COMPLETED, $this->order->fresh()->{Order::SURVEY_STATUS});
    }
}

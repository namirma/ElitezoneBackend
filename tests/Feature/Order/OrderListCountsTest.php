<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * شمارنده‌ی تب‌های فهرستِ سفارش (طرحِ جدید: «جاری (N)») — یک درخواست به‌جای شش،
 * و هر عدد باید دقیقاً `meta.total`ِ همان فهرست باشد.
 */
class OrderListCountsTest extends TestCase
{
    use DatabaseTransactions;

    private function order(array $attributes): Order
    {
        return Order::create($attributes + [
            Order::ORDER_NUMBER => 'CNT-'.Str::random(10),
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
    }

    #[Test]
    public function each_counter_equals_its_list_total(): void
    {
        $me = User::factory()->create();
        $employer = User::factory()->create();
        $stranger = User::factory()->create();

        // خرید برای خودم: دو جاری (یکی که فقط پرداخت‌کننده‌اش هستم)، یک تحویل‌شده.
        $this->order([Order::PLACER_USER_ID => $me->id, Order::PAYER_USER_ID => $me->id, Order::STATUS => Order::STATUS_PROCESSING]);
        $this->order([Order::PLACER_USER_ID => $stranger->id, Order::PAYER_USER_ID => $me->id, Order::STATUS => Order::STATUS_SHIPPED]);
        $this->order([Order::PLACER_USER_ID => $me->id, Order::PAYER_USER_ID => $me->id, Order::STATUS => Order::STATUS_DELIVERED]);
        // خرید برای دیگران: یک لغوشده (در «خودم» هم هست، چون ثبت‌کننده‌ام).
        $this->order([Order::PLACER_USER_ID => $me->id, Order::PAYER_USER_ID => $employer->id, Order::BENEFICIARY_USER_ID => $employer->id, Order::STATUS => Order::STATUS_CANCELLED]);
        // مالِ دیگری — نباید شمرده شود.
        $this->order([Order::PLACER_USER_ID => $stranger->id, Order::PAYER_USER_ID => $stranger->id, Order::STATUS => Order::STATUS_PROCESSING]);

        Sanctum::actingAs($me);
        $counts = $this->getJson('/api/profile/orders/counts')->assertOk()->json('data');

        $this->assertSame([
            'buy_myself' => ['current' => 2, 'delivered' => 1, 'cancelled' => 1],
            'buy_others' => ['current' => 0, 'delivered' => 0, 'cancelled' => 1],
        ], $counts);

        foreach (['buy_myself' => 'buy-myself', 'buy_others' => 'buy-others'] as $key => $path) {
            foreach (['current', 'delivered', 'cancelled'] as $group) {
                $this->assertSame(
                    $counts[$key][$group],
                    $this->getJson("/api/profile/orders/{$path}/{$group}?per_page=1")->assertOk()->json('meta.total'),
                    "{$path}/{$group}"
                );
            }
        }
    }
}

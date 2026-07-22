<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            Order::PLACER_USER_ID => User::factory(),
            Order::ORDER_NUMBER => 'ORD-'.fake()->unique()->numerify('########'),
            Order::STATUS => Order::STATUS_PENDING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [Order::PAYMENT_STATUS => Order::PAYMENT_PAID]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => [Order::STATUS => Order::STATUS_RESERVED]);
    }
}

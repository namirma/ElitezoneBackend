<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            Coupon::CODE => strtoupper(fake()->unique()->bothify('CPN-####')),
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => fake()->numberBetween(5, 50),
            Coupon::EXPIRES_AT => now()->addDays(30),
            Coupon::IS_ACTIVE => true,
        ];
    }

    public function fixed(int $amount = 100_000): static
    {
        return $this->state(fn () => [
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => $amount,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [Coupon::EXPIRES_AT => now()->subDay()]);
    }
}

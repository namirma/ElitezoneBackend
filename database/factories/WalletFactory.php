<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            Wallet::USER_ID => User::factory(),
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ];
    }

    public function withBalance(float $balance, float $hold = 0): static
    {
        return $this->state(fn () => [
            Wallet::BALANCE => $balance,
            Wallet::BALANCE_HOLD => $hold,
        ]);
    }
}

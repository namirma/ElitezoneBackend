<?php

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            WalletTransaction::WALLET_ID => Wallet::factory(),
            WalletTransaction::AMOUNT => fake()->numberBetween(100_000, 5_000_000),
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'factory transaction',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [WalletTransaction::STATUS => WalletTransaction::STATUS_PENDING]);
    }

    public function withdrawal(): static
    {
        return $this->state(fn () => [WalletTransaction::TYPE => WalletTransaction::TYPE_WITHDRAW]);
    }
}

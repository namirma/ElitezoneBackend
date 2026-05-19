<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'mobile' => '09'.fake()->unique()->numerify('#########'),
            'national_code' => fake()->unique()->numerify('##########'),
            'birth_date' => '13800101',
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'type_id' => function () {
                return UserType::firstOrCreate(
                    ['slug' => 'customer'],
                    ['name' => 'customer', 'icon' => 'user']
                )->id;
            },
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

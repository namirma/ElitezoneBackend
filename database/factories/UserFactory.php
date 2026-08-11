<?php

namespace Database\Factories;

use App\Models\User;
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
            // کاربرِ تازه هنوز نقشی انتخاب نکرده است.
            'type_id' => null,
        ];
    }

    /**
     * Return a user with no password set (simulates OTP-only registration).
     * The `email_verified_at` state was removed — users table has no such column;
     * this app uses OTP via `users.secret` / `users.secret_expires_at` instead.
     */
    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ]);
    }
}

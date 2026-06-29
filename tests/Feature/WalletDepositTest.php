<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletDepositTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * تست موفقیت‌آمیز درخواست شارژ کیف پول
     */
    public function test_user_can_request_deposit_successfully(): void
    {
        // 1. ایجاد نوع کاربری (با آیکون)
        $type = \App\Models\UserType::firstOrCreate(
            ['slug' => 'customer'],
            [
                'name' => 'customer',
                'icon' => 'user',
            ]
        );

        // 2. ایجاد کاربر
        $user = User::factory()->create(['type_id' => $type->id]);
        Sanctum::actingAs($user);

        $payload = [
            'amount' => 500000,
            'payment_method' => 'shaparak',
        ];

        // 3. ارسال درخواست
        $response = $this->postJson('/api/profile/financial/wallet/deposit', $payload);

        // 4. بررسی پاسخ
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'transaction_id',
                    'type',
                    'redirect_url',
                ],
            ]);

        // 5. بررسی دیتابیس
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $user->wallet->id,
            'amount' => 500000,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
        ]);
    }

    /**
     * تست شکست در صورت مبلغ نامعتبر
     */
    public function test_deposit_fails_if_amount_is_invalid(): void
    {
        // 1. ایجاد نوع کاربری (با آیکون)
        $type = \App\Models\UserType::firstOrCreate(
            ['slug' => 'customer'],
            [
                'name' => 'customer',
                'icon' => 'user', // <--- این خط اضافه شد
            ]
        );

        // 2. ایجاد کاربر
        $user = User::factory()->create(['type_id' => $type->id]);
        Sanctum::actingAs($user);

        // مبلغ کم
        $response = $this->postJson('/api/profile/financial/wallet/deposit', [
            'amount' => 1000,
            'payment_method' => 'shaparak',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }
}

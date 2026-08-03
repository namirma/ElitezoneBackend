<?php

namespace Tests\Feature\Checkout;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/checkout/payment/{order} — فیلد `is_active` هر روشِ پرداخت.
 *
 * تا پیش از این هر سه روش عددِ ثابتِ `true` می‌گرفتند، در حالی که درگاهِ کارت
 * بانکی هنوز مجوز نگرفته و `PasargadGateway::pay()` استثنا پرتاب می‌کند — یعنی
 * فرانت دکمه‌ای را فعال نشان می‌داد که کلیک روی آن قطعاً شکست می‌خورد.
 *
 * این تست همان قرارداد را پین می‌کند: `is_active` وضعیتِ واقعیِ سرور است، و روزی
 * که PASARGAD_* در .env پر شود بدونِ تغییرِ کد `true` می‌شود.
 */
class PaymentInfoGatewayStateTest extends TestCase
{
    use DatabaseTransactions;

    private function pendingOrderFor(User $user): Order
    {
        return Order::factory()->create([
            Order::PLACER_USER_ID => $user->id,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
        ]);
    }

    private function gateway(array $gateways, string $key): array
    {
        foreach ($gateways as $gateway) {
            if ($gateway['key'] === $key) {
                return $gateway;
            }
        }

        $this->fail("روش پرداخت [{$key}] در پاسخ نبود.");
    }

    #[Test]
    public function the_online_rail_is_inactive_while_the_bank_credentials_are_missing(): void
    {
        config()->set('payment.online_gateway', 'pasargad');
        config()->set('payment.gateways.pasargad.base_url', null);
        config()->set('payment.gateways.pasargad.api_key', null);
        config()->set('payment.gateways.pasargad.terminal_id', null);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $gateways = $this->getJson('/api/checkout/payment/'.$this->pendingOrderFor($user)->id)
            ->assertOk()
            ->json('data.gateways');

        $this->assertFalse($this->gateway($gateways, WalletTransaction::METHOD_GATEWAY)['is_active'],
            'بدون اعتبارنامهٔ بانک، رِیلِ پرداخت اینترنتی نباید فعال اعلام شود');
    }

    #[Test]
    public function the_online_rail_activates_once_the_bank_credentials_are_present(): void
    {
        config()->set('payment.online_gateway', 'pasargad');
        config()->set('payment.gateways.pasargad.base_url', 'https://example.test');
        config()->set('payment.gateways.pasargad.api_key', 'key');
        config()->set('payment.gateways.pasargad.terminal_id', 'terminal');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $gateways = $this->getJson('/api/checkout/payment/'.$this->pendingOrderFor($user)->id)
            ->assertOk()
            ->json('data.gateways');

        $this->assertTrue($this->gateway($gateways, WalletTransaction::METHOD_GATEWAY)['is_active'],
            'با پر شدن اعتبارنامه‌ها، رِیل باید بدون تغییر کد فعال شود');
    }

    #[Test]
    public function the_wallet_rail_stays_active_and_reports_credit_separately(): void
    {
        // کیف پول رِیلِ داخلی است و به مجوز بیرونی وابسته نیست؛ «پول کافی دارد یا
        // نه» سؤالِ جداست و با `has_credit` جواب داده می‌شود، نه با `is_active`.
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wallet = $this->gateway(
            $this->getJson('/api/checkout/payment/'.$this->pendingOrderFor($user)->id)
                ->assertOk()
                ->json('data.gateways'),
            'wallet'
        );

        $this->assertTrue($wallet['is_active']);
        $this->assertArrayHasKey('has_credit', $wallet);
    }

    #[Test]
    public function the_bank_transfer_rail_can_be_switched_off_by_config(): void
    {
        config()->set('payment.bank_transfer_enabled', false);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $gateways = $this->getJson('/api/checkout/payment/'.$this->pendingOrderFor($user)->id)
            ->assertOk()
            ->json('data.gateways');

        $this->assertFalse($this->gateway($gateways, WalletTransaction::METHOD_BANK_TRANSFER)['is_active']);
    }

    #[Test]
    public function an_unknown_configured_gateway_reports_inactive_instead_of_500ing(): void
    {
        // یک تنظیمِ اشتباه نباید صفحهٔ تسویه را بترکاند؛ فقط باید آن روش را خاموش
        // نشان دهد.
        config()->set('payment.online_gateway', 'a-gateway-that-does-not-exist');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $gateways = $this->getJson('/api/checkout/payment/'.$this->pendingOrderFor($user)->id)
            ->assertOk()
            ->json('data.gateways');

        $this->assertFalse($this->gateway($gateways, WalletTransaction::METHOD_GATEWAY)['is_active']);
    }
}

<?php

namespace Tests\Feature\Cart;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/cart/shipping-quote — pre-checkout freight estimate.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class ShippingQuoteTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function addressFor(User $u): Address
    {
        return Address::create([
            Address::USER_ID => $u->id,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);
    }

    #[Test]
    public function it_requires_an_address_id(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/cart/shipping-quote', [])->assertStatus(422);
    }

    #[Test]
    public function it_rejects_an_address_the_user_does_not_own(): void
    {
        $stranger = User::factory()->create();
        $foreign = $this->addressFor($stranger);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/cart/shipping-quote', ['address_id' => $foreign->id])
            ->assertStatus(403);
    }

    #[Test]
    public function it_returns_zero_freight_for_an_empty_cart(): void
    {
        $address = $this->addressFor($this->user);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/cart/shipping-quote', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.total', '0');
    }
}

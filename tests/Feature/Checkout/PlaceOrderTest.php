<?php

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for POST /api/checkout — the central order-placement endpoint.
 *
 * These are validation + authorization tests. The happy-path
 * end-to-end is intentionally not asserted here because PlaceOrderAction
 * needs a fully wired product → variant → price → factory → location →
 * cart graph plus pricing badge rates; that scope belongs in a separate
 * full-flow test if/when those fixtures exist.
 *
 * DatabaseTransactions: rolls back every test. Live local DB is NOT mutated.
 */
class PlaceOrderTest extends TestCase
{
    use DatabaseTransactions;

    private function makeAddress(User $user): Address
    {
        return Address::create([
            Address::USER_ID => $user->id,
            Address::POSTAL_ADDRESS => 'تهران، خیابان آزادی، پلاک ۱',
            Address::RECEIVER_NAME => 'علی محمدی',
            Address::RECEIVER_MOBILE => '09121111111',
        ]);
    }

    private function makeShippingMethod(): ShippingMethod
    {
        return ShippingMethod::create([
            'name' => 'پست پیشتاز',
            'code' => 'TEST_'.uniqid(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Authentication
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_requires_authentication(): void
    {
        $response = $this->postJson('/api/checkout');

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_requires_address_id(): void
    {
        $user = User::factory()->create();
        $shipping = $this->makeShippingMethod();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'shipping_method_id' => $shipping->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id']);
    }

    #[Test]
    public function it_requires_shipping_method_id(): void
    {
        $user = User::factory()->create();
        $address = $this->makeAddress($user);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'address_id' => $address->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipping_method_id']);
    }

    #[Test]
    public function it_rejects_unknown_shipping_method(): void
    {
        $user = User::factory()->create();
        $address = $this->makeAddress($user);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'address_id' => $address->id,
            'shipping_method_id' => 9999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipping_method_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Authorization — address ownership
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_rejects_address_belonging_to_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceAddress = $this->makeAddress($alice);
        $shipping = $this->makeShippingMethod();

        Sanctum::actingAs($bob);

        // Bob tries to place an order using Alice's address
        $response = $this->postJson('/api/checkout', [
            'address_id' => $aliceAddress->id,
            'shipping_method_id' => $shipping->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id']);
    }

    #[Test]
    public function it_rejects_unknown_address(): void
    {
        $user = User::factory()->create();
        $shipping = $this->makeShippingMethod();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'address_id' => 9999999,
            'shipping_method_id' => $shipping->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cart pre-condition
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_error_when_cart_is_empty(): void
    {
        $user = User::factory()->create();
        $address = $this->makeAddress($user);
        $shipping = $this->makeShippingMethod();
        Sanctum::actingAs($user);

        // No cart created for this user, so it should be empty
        $response = $this->postJson('/api/checkout', [
            'address_id' => $address->id,
            'shipping_method_id' => $shipping->id,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Description constraint
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_rejects_description_over_1000_chars(): void
    {
        $user = User::factory()->create();
        $address = $this->makeAddress($user);
        $shipping = $this->makeShippingMethod();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'address_id' => $address->id,
            'shipping_method_id' => $shipping->id,
            'description' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['description']);
    }

    #[Test]
    public function it_rejects_beneficiary_user_id_that_does_not_exist(): void
    {
        $user = User::factory()->create();
        $address = $this->makeAddress($user);
        $shipping = $this->makeShippingMethod();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'address_id' => $address->id,
            'shipping_method_id' => $shipping->id,
            'beneficiary_user_id' => 9999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['beneficiary_user_id']);
    }
}

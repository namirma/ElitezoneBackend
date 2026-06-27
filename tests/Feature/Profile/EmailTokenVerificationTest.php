<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/profile/check-email-token must match the EXACT token. A previous
 * where([column], $token) bug made Eloquent ignore the token (always-true WHERE),
 * so any token verified the first unverified profile — even another user's.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class EmailTokenVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithEmailToken(string $token): User
    {
        $user = User::factory()->create();
        UserProfile::updateOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::NATIONAL_CODE => str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
                UserProfile::EMAIL => 'u'.$user->id.'@example.com',
                UserProfile::EMAIL_VERIFICATION_TOKEN => $token,
                UserProfile::EMAIL_VERIFIED_AT => null,
            ]
        );

        return $user;
    }

    #[Test]
    public function a_wrong_token_verifies_nothing(): void
    {
        $owner = $this->userWithEmailToken('correct-token');

        Sanctum::actingAs($owner);
        $this->postJson('/api/profile/check-email-token', ['token' => 'totally-wrong-token'])
            ->assertStatus(400);

        $this->assertNull($owner->profile->fresh()->{UserProfile::EMAIL_VERIFIED_AT});
    }

    #[Test]
    public function a_token_only_verifies_its_own_profile(): void
    {
        $owner = $this->userWithEmailToken('token-A');
        $other = $this->userWithEmailToken('token-B');

        Sanctum::actingAs($owner);
        $this->postJson('/api/profile/check-email-token', ['token' => 'token-A'])
            ->assertOk();

        $this->assertNotNull($owner->profile->fresh()->{UserProfile::EMAIL_VERIFIED_AT});
        // The other user's email must remain unverified.
        $this->assertNull($other->profile->fresh()->{UserProfile::EMAIL_VERIFIED_AT});
    }
}

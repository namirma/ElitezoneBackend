<?php

namespace Tests\Feature\Profile;

use App\Models\Badge;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the server-side saze90.com badge proxy (GET /api/profile/badge).
 *
 * No real saze90 call: Http::fake() stubs get-certificate. DatabaseTransactions rolls back.
 */
class BadgeSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // the service caches successful lookups per-mobile
        config(['services.saze90.enabled' => true, 'services.saze90.base_url' => 'https://www.saze90.com']);
    }

    private function fakeSaze90(string $message, int $status = 200): void
    {
        Http::fake([
            '*get-certificate*' => Http::response(['message' => $message, 'status' => $status], 200),
        ]);
    }

    /** @test */
    public function it_handles_the_real_no_badge_shape_message_false(): void
    {
        // Verified against the LIVE server: a mobile with no badge returns
        // {"message":false,"status":200}. The boolean false must resolve to "no badge".
        Http::fake(['*get-certificate*' => Http::response(['message' => false, 'status' => 200], 200)]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.badge', null);

        $this->assertNull($user->fresh()->badge_id);
    }

    /** @test */
    public function it_persists_a_gold_tier_from_saze90_onto_the_user(): void
    {
        $this->fakeSaze90('gold');
        $gold = Badge::create([Badge::NAME => 'طلایی', Badge::CODE => 'gold']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.badge', 'gold');

        $this->assertSame($gold->id, (int) $user->fresh()->badge_id, 'commission tier persisted');
        $this->assertTrue($user->badges()->where('badges.id', $gold->id)->exists(), 'display pivot synced');
    }

    /** @test */
    public function changing_tier_replaces_the_previous_tier_and_does_not_accumulate(): void
    {
        $gold = Badge::create([Badge::NAME => 'طلایی', Badge::CODE => 'gold']);
        $silver = Badge::create([Badge::NAME => 'نقره‌ای', Badge::CODE => 'silver']);

        // Start as gold (badge_id + pivot), then saze90 reports silver.
        $user = User::factory()->create([User::BADGE_ID => $gold->id]);
        $user->badges()->attach($gold->id);
        Sanctum::actingAs($user);

        $this->fakeSaze90('silver');
        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.badge', 'silver');

        $this->assertSame($silver->id, (int) $user->fresh()->badge_id, 'commission tier updated');
        $this->assertFalse($user->badges()->where('badges.id', $gold->id)->exists(), 'stale tier removed from pivot');
        $this->assertTrue($user->badges()->where('badges.id', $silver->id)->exists(), 'new tier in pivot');
    }

    /** @test */
    public function it_passes_the_normalized_mobile_to_saze90(): void
    {
        $this->fakeSaze90('silver');
        Badge::create([Badge::NAME => 'نقره‌ای', Badge::CODE => 'silver']);

        $user = User::factory()->create([User::MOBILE => '09121112233']);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get-certificate')
            && str_contains($request->url(), 'mobile=09121112233'));
    }

    /** @test */
    public function a_non_tier_code_is_returned_but_does_not_change_the_commission_badge(): void
    {
        // e.g. the «کاربر سازه ۹۰» display code — returned for display, never a commission tier.
        $this->fakeSaze90('serial');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.badge', 'serial');

        $this->assertNull($user->fresh()->badge_id, 'non-tier code must not set the commission badge');
    }

    /** @test */
    public function a_saze90_outage_fails_safe_and_never_clears_an_existing_tier(): void
    {
        $gold = Badge::create([Badge::NAME => 'طلایی', Badge::CODE => 'gold']);
        $user = User::factory()->create([User::BADGE_ID => $gold->id]);
        Sanctum::actingAs($user);

        Http::fake(['*get-certificate*' => Http::response('', 500)]);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.badge', null);

        $this->assertSame($gold->id, (int) $user->fresh()->badge_id, 'transient outage must not downgrade the tier');
    }

    /** @test */
    public function a_first_year_member_is_flagged_pioneer(): void
    {
        $this->fakeSaze90('', 401); // no saze90 badge
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.is_pioneer', true)
            ->assertJsonPath('data.badges', ['pioneer']);
    }

    /** @test */
    public function a_member_past_their_first_year_is_not_pioneer(): void
    {
        $this->fakeSaze90('', 401);
        $user = User::factory()->create();
        $user->forceFill(['created_at' => now()->subYears(2)])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.is_pioneer', false);
    }
}

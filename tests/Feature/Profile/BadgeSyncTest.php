<?php

namespace Tests\Feature\Profile;

use App\Models\Badge;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserType;
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
    public function a_non_tier_code_maps_to_saze90_membership_without_a_commission_tier(): void
    {
        // Any non-false, non-tier code means the person is a saze90 member → badge "saze90",
        // is_saze90_user true, but NO commission tier on the user.
        $this->fakeSaze90('serial');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/badge')
            ->assertOk()
            ->assertJsonPath('data.badge', 'saze90')
            ->assertJsonPath('data.is_saze90_user', true);

        $this->assertNull($user->fresh()->badge_id, 'non-tier code must not set the commission badge');
        $this->assertTrue($user->badges()->where('badges.code', 'saze90')->exists(), 'saze90 badge in pivot');
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
            ->assertJsonPath('data.is_saze90_user', false)
            ->assertJsonPath('data.badges.0.code', 'pioneer');

        $this->assertTrue($user->badges()->where('badges.code', 'pioneer')->exists(), 'pioneer badge in pivot');
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

    /** @test */
    public function get_profile_alone_returns_fresh_badge_data_on_the_first_call(): void
    {
        // No prior /badge call — GET /api/profile must already carry the badge state
        // (single source of truth, no sync race / refresh needed).
        $this->fakeSaze90('gold');
        Badge::firstOrCreate([Badge::CODE => 'gold'], [Badge::NAME => 'طلایی']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.is_saze90_user', true)
            ->assertJsonPath('data.badge', 'gold');

        // badges contains gold (order not guaranteed; the user is also pioneer).
        $codes = collect($res->json('data.badges'))->pluck('code')->all();
        $this->assertContains('gold', $codes);
    }

    /** @test */
    public function a_saze90_member_engineer_is_persisted_and_auto_verified(): void
    {
        $this->fakeSaze90('gold');
        Badge::firstOrCreate(['code' => 'gold'], ['name' => 'طلایی']);

        $type = UserType::firstOrCreate(['slug' => 'expert'], ['name' => 'expert', 'icon' => 'u']);
        $user = User::factory()->create(['type_id' => $type->id]);
        EngineerProfile::create([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
            EngineerProfile::IS_VERIFIED => false, // admin has NOT verified the docs
        ]);
        Sanctum::actingAs($user);

        // The badge sync persists is_saze90_user on the engineer profile…
        $this->getJson('/api/profile/badge')->assertOk()->assertJsonPath('data.is_saze90_user', true);

        // …and GET /api/profile then reflects it + auto-verifies the engineer.
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.engineer.is_saze90_user', true)
            ->assertJsonPath('data.engineer.is_verified', true)
            ->assertJsonPath('data.is_verified', true);
    }
}

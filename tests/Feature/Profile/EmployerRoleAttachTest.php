<?php

namespace Tests\Feature\Profile;

use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Completing the employer profile (POST /api/profile/employer) must grant the
 * `employer` Laratrust role. Without it the role-gated consultant endpoints
 * (GET /api/profile/consultants) return 403 — the reported "مشاورین" tab failure.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class EmployerRoleAttachTest extends TestCase
{
    use DatabaseTransactions;

    private function employerType(): UserType
    {
        return UserType::firstOrCreate(['slug' => 'employer'], ['name' => 'کارفرما', 'icon' => 'briefcase']);
    }

    #[Test]
    public function completing_the_employer_profile_attaches_the_employer_role(): void
    {
        Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']);

        $type = $this->employerType();
        $user = User::factory()->create([User::TYPE_ID => $type->id]);

        $this->assertFalse($user->hasRole('employer'));

        Sanctum::actingAs($user);

        $this->postJson('/api/profile/employer', [])->assertOk();

        $this->assertTrue($user->fresh()->hasRole('employer'));
    }

    #[Test]
    public function an_employer_can_reach_the_consultants_endpoint_after_completing_the_profile(): void
    {
        Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']);

        $type = $this->employerType();
        $user = User::factory()->create([User::TYPE_ID => $type->id]);
        Sanctum::actingAs($user);

        // Before: role-gated endpoint is forbidden.
        $this->getJson('/api/profile/consultants')->assertForbidden();

        // Complete the employer profile → role attached.
        $this->postJson('/api/profile/employer', [])->assertOk();

        // After: the same endpoint is reachable (no longer 403).
        $this->getJson('/api/profile/consultants')->assertOk();
    }
}

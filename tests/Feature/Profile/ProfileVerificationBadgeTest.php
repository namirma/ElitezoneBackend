<?php

namespace Tests\Feature\Profile;

use App\Models\ContractorProfile;
use App\Models\EngineerProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پوشش رفتار فیلد is_verified در پاسخ GET /api/profile.
 *
 * قرارداد:
 *   - الیت یار (type.slug = elite_yar) با پروفایل تأییدشده → true
 *   - الیت یار با پروفایل تأییدنشده (یا بدون مدرک) → false
 *   - نقش‌های غیرالیت یار (employer/supplier/customer) → null
 */
class ProfileVerificationBadgeTest extends TestCase
{
    use DatabaseTransactions;

    private function userType(string $slug): UserType
    {
        return UserType::firstOrCreate(
            [UserType::SLUG => $slug],
            [UserType::NAME => $slug, UserType::ICON => 'user']
        );
    }

    private function eliteYarUser(): User
    {
        $user = User::factory()->create([
            User::TYPE_ID => $this->userType('elite_yar')->id,
        ]);

        // `is_verified` از روی نقشِ `elite_yar` تصمیم می‌گیرد، نه type.slug —
        // همان نقشی که SyncUserRolesAction هنگام انتخابِ نقش وصل می‌کند. بدون
        // این، fixture کاربری می‌سازد که در محصول وجود ندارد.
        $user->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'الیت یار']));

        return $user;
    }

    #[Test]
    public function elite_yar_without_documents_returns_is_verified_false(): void
    {
        $user = $this->eliteYarUser();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertFalse($response->json('data.is_verified'));
    }

    #[Test]
    public function verified_engineer_returns_is_verified_true(): void
    {
        $user = $this->eliteYarUser();
        EngineerProfile::forceCreate([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::IS_VERIFIED => true,
            'engineer_member' => false,
            'student' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertTrue($response->json('data.is_verified'));
    }

    #[Test]
    public function unverified_engineer_returns_is_verified_false(): void
    {
        $user = $this->eliteYarUser();
        EngineerProfile::forceCreate([
            EngineerProfile::USER_ID => $user->id,
            EngineerProfile::IS_VERIFIED => false,
            'engineer_member' => false,
            'student' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertFalse($response->json('data.is_verified'));
    }

    #[Test]
    public function verified_contractor_returns_is_verified_true(): void
    {
        $user = $this->eliteYarUser();
        ContractorProfile::forceCreate([
            ContractorProfile::USER_ID => $user->id,
            ContractorProfile::IS_VERIFIED => true,
            'engineer_member' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertTrue($response->json('data.is_verified'));
    }

    #[Test]
    public function employer_returns_is_verified_null(): void
    {
        $user = User::factory()->create([
            User::TYPE_ID => $this->userType('employer')->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertNull($response->json('data.is_verified'));
    }
}

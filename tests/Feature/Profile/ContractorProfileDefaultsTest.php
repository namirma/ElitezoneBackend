<?php

namespace Tests\Feature\Profile;

use App\Models\ContractorProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * رگرسیون: `POST /api/profile/contractor` برای کاربری که هنوز پروفایل ندارد
 * ۵۰۰ می‌داد و متنِ خامِ SQL را هم نشت می‌کرد.
 *
 * ستونِ `not_related` در دیتابیس `NOT NULL DEFAULT 1` است، ولی کنترلر مقدارش را
 * با `?? $existing?->not_related` می‌ساخت و **فالبکِ سوم را نداشت**. برای کاربرِ
 * بی‌پروفایل هر دو طرفِ `??` نال می‌شدند، پس یک نالِ **صریح** در INSERT می‌رفت که
 * دیفالتِ دیتابیس را خنثی می‌کند → «Column 'not_related' cannot be null».
 *
 * خطِ `engineer_member` در همان آرایه از قبل `?? false` داشت؛ این یکی جا مانده بود.
 *
 * DatabaseTransactions: همه‌ی نوشتن‌ها برگردانده می‌شوند.
 */
class ContractorProfileDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_first_time_contractor_profile_falls_back_to_the_column_default(): void
    {
        Role::firstOrCreate(['name' => 'contractor'], ['display_name' => 'پیمانکار']);
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->assertNull(
            ContractorProfile::where(ContractorProfile::USER_ID, $user->id)->first(),
            'the user must start without a contractor profile for this regression to apply'
        );

        // بدنه‌ی خالی — همان چیزی که ۵۰۰ می‌داد
        $this->postJson('/api/profile/contractor', [])->assertStatus(200);

        $profile = ContractorProfile::where(ContractorProfile::USER_ID, $user->id)->first();

        $this->assertNotNull($profile, 'the profile row should have been created');
        $this->assertNotNull(
            $profile->{ContractorProfile::NOT_RELATED},
            'not_related is NOT NULL in the schema; a null here is the 500 coming back'
        );
        $this->assertEquals(1, (int) $profile->{ContractorProfile::NOT_RELATED});
    }

    /**
     * مقدارِ صریحِ کاربر نباید با فالبک بازنویسی شود — وگرنه رفعِ بالا خودش
     * یک باگِ داده می‌شد.
     */
    #[Test]
    public function an_explicit_false_is_not_overwritten_by_the_fallback(): void
    {
        Role::firstOrCreate(['name' => 'contractor'], ['display_name' => 'پیمانکار']);
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/contractor', ['not_related' => false])->assertStatus(200);

        $profile = ContractorProfile::where(ContractorProfile::USER_ID, $user->id)->first();

        $this->assertNotNull($profile);
        $this->assertEquals(0, (int) $profile->{ContractorProfile::NOT_RELATED});
    }
}

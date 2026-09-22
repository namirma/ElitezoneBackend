<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserType;
use App\Services\EduixScraperService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnablesVerificationMethods;
use Tests\TestCase;

/**
 * استعلام مدرک تحصیلی فقط برای کد ملیِ خودِ کاربر.
 *
 * پیش از این گارد به‌صورت `if ($user->national_code && ...)` نوشته شده بود، پس
 * کاربری که هنوز احراز هویت نکرده بود (national_code = null) می‌توانست کد ملیِ
 * هر شخص دیگری را استعلام کند و رکورد تحصیلیِ او را زیر پروفایل خودش ثبت و
 * سپس به تأیید ادمین برساند.
 */
class EduixIdentityGuardTest extends TestCase
{
    use DatabaseTransactions, EnablesVerificationMethods;

    protected function setUp(): void
    {
        parent::setUp();
        // این تست مسیرِ استعلامِ مدرک را می‌زند؛ سیاستِ لانچ آن را خاموش می‌کارد.
        $this->enableVerificationMethod('education');
    }

    private function eliteYar(?string $nationalCode): User
    {
        // user_types.icon پیش‌فرض DB ندارد — همان مقدارِ UserTypeSeeder.
        $type = UserType::firstOrCreate(
            ['slug' => 'elite_yar'],
            ['name' => 'الیت یار', 'icon' => 'star'],
        );

        $user = User::factory()->create([
            User::TYPE_ID => $type->id,
            User::NATIONAL_CODE => $nationalCode,
        ]);
        // `elite_yar` = قصد (لحظه‌ی انتخابِ نقش)، `engineer` = مدرکِ تأییدشده.
        // گاردِ مسیر فقط اولی را می‌خواند؛ دومی اینجا می‌ماند چون این تست
        // مسیرِ استعلامِ مدارک تحصیلی را می‌زند.
        $user->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'الیت یار']));
        $user->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'Engineer']));

        return $user;
    }

    #[Test]
    public function a_user_without_a_national_code_cannot_query_anyone(): void
    {
        // اسکرپر نباید اصلاً صدا زده شود — گارد باید قبل از آن ببندد.
        $this->mock(EduixScraperService::class)->shouldNotReceive('fetchData');

        Sanctum::actingAs($this->eliteYar(null));

        $this->postJson('/api/profile/education/fetch', [
            'national_code' => '1234567890',
            'trace_code' => 'ABC123',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_user_cannot_query_someone_elses_national_code(): void
    {
        $this->mock(EduixScraperService::class)->shouldNotReceive('fetchData');

        Sanctum::actingAs($this->eliteYar('1111111111'));

        $this->postJson('/api/profile/education/fetch', [
            'national_code' => '2222222222',
            'trace_code' => 'ABC123',
        ])->assertStatus(422);
    }

    #[Test]
    public function national_code_must_be_ten_digits(): void
    {
        Sanctum::actingAs($this->eliteYar('1111111111'));

        $this->postJson('/api/profile/education/fetch', [
            'national_code' => '111',
            'trace_code' => 'ABC123',
        ])->assertStatus(422)->assertJsonValidationErrors('national_code');
    }

    #[Test]
    public function trace_code_rejects_query_injection_characters(): void
    {
        Sanctum::actingAs($this->eliteYar('1111111111'));

        $this->postJson('/api/profile/education/fetch', [
            'national_code' => '1111111111',
            'trace_code' => 'ABC&nationalCode=2222222222',
        ])->assertStatus(422)->assertJsonValidationErrors('trace_code');
    }

    #[Test]
    public function a_user_may_query_their_own_national_code(): void
    {
        // همان شکلی که EduixScraperService::fetchData برمی‌گرداند (کل mapping).
        $this->mock(EduixScraperService::class)
            ->shouldReceive('fetchData')
            ->once()
            ->andReturn([
                'name' => 'علی',
                'family' => 'رضایی',
                'father_name' => 'حسن',
                'national_code' => '1111111111',
                'id_number' => '123',
                'birth_date' => '1370/01/01',
                'university' => 'دانشگاه تهران',
                'field' => 'عمران',
                'course_type' => 'روزانه',
                'entry_date' => '1388/07/01',
                'graduate_date' => '1392/07/01',
                'gpa' => '17.5',
            ]);

        Sanctum::actingAs($this->eliteYar('1111111111'));

        $this->postJson('/api/profile/education/fetch', [
            'national_code' => '1111111111',
            'trace_code' => 'ABC123',
        ])->assertOk();
    }
}

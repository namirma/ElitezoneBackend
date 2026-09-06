<?php

namespace Tests\Feature\Supplier;

use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «وضعیت درخواستِ سوپلایری من چیست؟»
 *
 * تا پیش از این هیچ اندپوینتی به این سؤال جواب نمی‌داد. زیر `/profile/supplier`
 * فقط `POST` (ثبت درخواست) و `DELETE` وجود داشت، و `GET /api/profile` هم پروفایل
 * سوپلایر را **فقط وقتی** لود می‌کرد که کاربر نقشِ `supplier` داشته باشد — نقشی که
 * طبق قاعدهٔ پروژه تنها با تأییدِ ادمین وصل می‌شود.
 *
 * نتیجه‌اش دقیقاً برعکسِ چیزی بود که لازم است: کسی که **منتظرِ تأیید** است یا
 * **رد شده**، هیچ راهی نداشت وضعیت خودش را ببیند؛ فقط کسی وضعیتش را می‌دید که
 * دیگر لازم نداشت بداند.
 */
class SupplierOwnProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function applicant(string $status): User
    {
        $user = User::factory()->create();

        $profile = SupplierProfile::create([
            SupplierProfile::USER_ID => $user->id,
            SupplierProfile::ACTIVITY_DESCRIPTION => 'تولید میلگرد',
        ]);

        // `status` عمداً در `$fillable` نیست — فقط اکشن‌های ادمین با forceFill می‌نویسند.
        $profile->forceFill([SupplierProfile::STATUS => $status])->save();

        return $user;
    }

    #[Test]
    public function a_pending_applicant_can_read_their_own_status(): void
    {
        $user = $this->applicant(SupplierProfile::STATUS_PENDING);

        Sanctum::actingAs($user);

        $this->getJson('/api/profile/supplier')
            ->assertOk()
            ->assertJsonPath('data.status', SupplierProfile::STATUS_PENDING);
    }

    #[Test]
    public function a_rejected_applicant_can_still_read_their_status(): void
    {
        // مهم‌ترین حالت: رد شدن نقش را جدا می‌کند، پس شرطِ قبلی («نقش supplier داری؟»)
        // دقیقاً کسی را کور می‌کرد که باید بفهمد درخواستش رد شده.
        $user = $this->applicant(SupplierProfile::STATUS_REJECTED);

        Sanctum::actingAs($user);

        $this->getJson('/api/profile/supplier')
            ->assertOk()
            ->assertJsonPath('data.status', SupplierProfile::STATUS_REJECTED);
    }

    #[Test]
    public function an_approved_supplier_reads_their_profile_too(): void
    {
        $user = $this->applicant(SupplierProfile::STATUS_APPROVED);
        $user->addRole(Role::firstOrCreate(['name' => 'supplier'], [
            'display_name' => 'Supplier',
            'description' => 'Supplier',
        ]));

        Sanctum::actingAs($user);

        $this->getJson('/api/profile/supplier')
            ->assertOk()
            ->assertJsonPath('data.status', SupplierProfile::STATUS_APPROVED);
    }

    #[Test]
    public function a_user_who_never_applied_gets_a_null_payload_not_an_error(): void
    {
        // «هنوز درخواست نداده‌ام» یک حالتِ عادی است، نه خطا — پنل نباید مجبور شود
        // یک ۴۰۴ را به‌عنوان وضعیتِ معمولی هندل کند.
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function it_never_returns_another_users_profile(): void
    {
        $other = $this->applicant(SupplierProfile::STATUS_APPROVED);
        $me = User::factory()->create();

        Sanctum::actingAs($me);

        // خودم درخواستی ندارم، پس حتی با وجودِ پروفایلِ دیگری باید null بگیرم.
        $this->getJson('/api/profile/supplier')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertTrue(
            SupplierProfile::where(SupplierProfile::USER_ID, $other->id)->exists(),
            'پروفایل کاربر دیگر باید دست‌نخورده بماند.'
        );
    }

    #[Test]
    public function a_guest_cannot_read_it(): void
    {
        $this->getJson('/api/profile/supplier')->assertUnauthorized();
    }
}

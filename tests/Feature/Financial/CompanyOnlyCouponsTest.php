<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * مدیریت، دورِ ششم، پرسشِ ۱ (۱۴۰۵/۰۷/۱۵): «در این ورژن فقط شرکت کوپن تخفیف صادر می‌کند. صدورِ کوپنِ
 * تخفیف توسطِ تأمین‌کننده و الیت‌یار را متوقف کنید.» ماژولِ مشارکت می‌ماند (قانونِ یک) پشتِ
 * `features.partner_coupons` (پیش‌فرض خاموش).
 */
class CompanyOnlyCouponsTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertFalse((bool) config('features.partner_coupons'), 'پیش‌فرض باید خاموش باشد.');

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'supplier']));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }

    #[Test]
    public function a_supplier_can_no_longer_issue_a_coupon(): void
    {
        Sanctum::actingAs($this->supplier);

        $this->postJson('/api/profile/supplier/coupons', [
            'code' => 'SUP-'.strtoupper(Str::random(5)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => [['funder_type' => CouponFunder::TYPE_SUPPLIER, 'share_percent' => 100]],
        ])->assertStatus(422)->assertJsonPath('message', __('coupons.partner_coupons_off'));

        $this->assertSame(0, Coupon::where(Coupon::CREATED_BY_USER_ID, $this->supplier->id)->count());
    }

    #[Test]
    public function the_admin_can_issue_a_company_coupon_but_not_one_shared_with_a_supplier(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/coupons', [
            'code' => 'MIX-'.strtoupper(Str::random(5)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => [
                ['funder_type' => CouponFunder::TYPE_SUPPLIER, 'funder_mobile' => $this->supplier->mobile, 'share_percent' => 60],
                ['funder_type' => CouponFunder::TYPE_COMPANY, 'share_percent' => 40],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['funders' => __('coupons.partner_coupons_off')]);

        $this->postJson('/api/admin/coupons', [
            'code' => 'CO-'.strtoupper(Str::random(5)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
        ])->assertCreated();
    }

    #[Test]
    public function an_existing_shared_coupon_is_refused_at_checkout_and_its_pending_share_cannot_be_accepted(): void
    {
        $coupon = Coupon::create([
            Coupon::CODE => 'OLD-'.strtoupper(Str::random(5)),
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 500_000,
            Coupon::IS_ACTIVE => true,
        ]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_COMPANY,
            CouponFunder::FUNDER_USER_ID => null,
            CouponFunder::SHARE_PERCENT => 50,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
        ]);
        $pending = $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $this->supplier->id,
            CouponFunder::SHARE_PERCENT => 50,
            CouponFunder::STATUS => CouponFunder::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->supplier);
        $this->postJson("/api/profile/coupon-funders/{$pending->id}/accept")
            ->assertStatus(422)->assertJsonPath('message', __('coupons.partner_coupons_off'));
        $this->assertSame(CouponFunder::STATUS_PENDING, $pending->fresh()->{CouponFunder::STATUS});

        // حتی اگر ردیف پذیرفته شده بود، در پرداخت رد می‌شود.
        $pending->forceFill([CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED])->save();
        $buyer = User::factory()->create();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(__('coupons.partner_coupons_off'));
        app(CouponService::class)->validateForCheckout($buyer, $coupon->{Coupon::CODE});
    }
}

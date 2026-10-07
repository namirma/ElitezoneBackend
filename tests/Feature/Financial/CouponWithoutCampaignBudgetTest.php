<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketing\CouponFundingService;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FundsCouponWallet;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * مدیریت، دورِ ششم، پرسشِ ۴ (۱۴۰۵/۰۷/۱۵): «فقط کیف پول؛ بودجه‌ی کمپین حذف شود.» سقفِ بودجه پشتِ
 * `features.campaign_budget` (پیش‌فرض خاموش) می‌رود؛ «چندبارمصرف» که تا امروز فقط از وجودِ بودجه
 * خوانده می‌شد، ستونِ صریحِ `is_multi_use` می‌گیرد (پرشده از کوپن‌های بودجه‌دارِ موجود).
 */
class CouponWithoutCampaignBudgetTest extends TestCase
{
    use DatabaseTransactions;
    use FundsCouponWallet;
    use GrantsAdminPermissions;

    private function coupon(array $attributes): Coupon
    {
        return Coupon::create($attributes + [
            Coupon::CODE => 'C6-'.strtoupper(Str::random(6)),
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 400_000,
            Coupon::IS_ACTIVE => true,
        ]);
    }

    #[Test]
    public function a_multi_use_coupon_is_no_longer_capped_by_a_campaign_budget(): void
    {
        $this->assertFalse((bool) config('features.campaign_budget'), 'پیش‌فرض باید خاموش باشد.');

        $coupon = $this->coupon([Coupon::IS_MULTI_USE => true, Coupon::BUDGET_TOTAL => 1_000_000]);
        $coupon->forceFill([Coupon::BUDGET_USED => 1_000_000])->save();
        $this->fundCouponWallet(10_000_000);

        // بودجه «تمام شده»، ولی دیگر سقف نیست: اعتبارسنجی و برنامه‌ی تأمین از کیف پول رد نمی‌کنند.
        $validated = app(CouponService::class)->validateForCheckout(User::factory()->create(), $coupon->{Coupon::CODE});
        $this->assertTrue($validated->isCampaign());
        $legs = app(CouponFundingService::class)->plan($coupon, 400_000, ['company' => 0, 'elite_yar' => 0], null, null);
        $this->assertEqualsWithDelta(400_000.0, $legs[0]['amount'], 0.0);
    }

    #[Test]
    public function a_single_use_coupon_stays_single_use(): void
    {
        $coupon = $this->coupon([]);
        $coupon->forceFill([Coupon::USED_AT => now()])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(__('coupons.already_used'));
        app(CouponService::class)->validateForCheckout(User::factory()->create(), $coupon->{Coupon::CODE});
    }

    #[Test]
    public function the_admin_marks_multi_use_explicitly_and_the_legacy_budget_field_still_means_multi_use(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $explicit = $this->postJson('/api/admin/coupons', [
            'code' => 'MU-'.strtoupper(Str::random(5)), 'type' => Coupon::TYPE_PERCENT, 'amount' => 5,
            'is_multi_use' => true,
        ])->assertCreated();
        $explicit->assertJsonPath('data.is_multi_use', true)->assertJsonPath('data.is_campaign', true);

        // پنلِ فعلیِ بک‌آفیس هنوز «بودجه‌ی کمپین» می‌فرستد — یعنی همان «چندبارمصرف».
        $this->postJson('/api/admin/coupons', [
            'code' => 'LB-'.strtoupper(Str::random(5)), 'type' => Coupon::TYPE_PERCENT, 'amount' => 5,
            'budget_total' => 2_000_000,
        ])->assertCreated()->assertJsonPath('data.is_multi_use', true);

        $this->postJson('/api/admin/coupons', [
            'code' => 'SU-'.strtoupper(Str::random(5)), 'type' => Coupon::TYPE_FIXED, 'amount' => 1000,
        ])->assertCreated()->assertJsonPath('data.is_multi_use', false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Exceptions\BusinessException;
use App\Exceptions\CouponWalletShortException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Services\Marketing\CouponFundingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FundsCouponWallet;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * مدیریت، دورِ ششم، پرسشِ ۵ (۱۴۰۵/۰۷/۱۵): «کوپن رد شود و به مدیرِ مالی هم خبر داده شود که کیف پول
 * کم است.» هشدار پس از بیرون‌رفتنِ استثنا از تراکنش‌ها ثبت می‌شود (در `render`) — وگرنه همراهِ ثبتِ
 * سفارش rollback می‌شد. هر چند ساعت یک بار، نه برای هر خریدار.
 */
class CouponWalletLowAlertTest extends TestCase
{
    use DatabaseTransactions;
    use FundsCouponWallet;
    use GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendSmsJob::class]);
    }

    private function admin(bool $finance, string $firstName): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        if ($finance) {
            $this->grantAllAdminPermissions($admin);
        }
        UserProfile::updateOrCreate([UserProfile::USER_ID => $admin->id], [UserProfile::NAME => $firstName]);

        return $admin;
    }

    private function refusal(): CouponWalletShortException
    {
        $this->fundCouponWallet(100_000);
        $coupon = Coupon::create([
            Coupon::CODE => 'LOW-'.strtoupper(Str::random(5)),
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 5_000_000,
            Coupon::IS_ACTIVE => true,
        ]);

        try {
            app(CouponFundingService::class)->plan($coupon, 5_000_000, ['company' => 0, 'elite_yar' => 0], null, null);
        } catch (CouponWalletShortException $e) {
            return $e;
        }

        $this->fail('کیف پولِ کمبوددار باید کوپن را رد کند.');
    }

    #[Test]
    public function a_refused_coupon_alerts_the_finance_admins_once_per_window(): void
    {
        $finance = $this->admin(true, 'مریم');
        $other = $this->admin(false, 'رضا');

        $e = $this->refusal();
        $this->assertInstanceOf(BusinessException::class, $e, 'همان ۴۲۲ و پیامِ قبلی برای خریدار');
        $this->assertSame(422, $e->render(request())->getStatusCode());

        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) use ($finance) {
            [$mobile, $key] = (fn () => [$this->mobile, $this->templateKey])->call($job);

            return $mobile === $finance->mobile && $key === 'coupon_wallet_low_admin';
        });
        Bus::assertNotDispatched(SendSmsJob::class, fn (SendSmsJob $job) => (fn () => $this->mobile)->call($job) === $other->mobile);

        $notice = UserNotification::where(UserNotification::USER_ID, $finance->id)->sole();
        $this->assertStringStartsWith('مریم عزیز،', (string) $notice->{UserNotification::BODY});
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $other->id)->count());

        // ردِ بعدی در همان پنجره هشدارِ دوباره نمی‌سازد.
        $this->refusal()->render(request());
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $finance->id)->count());
    }

    #[Test]
    public function the_salutation_disappears_cleanly_when_the_first_name_is_unknown(): void
    {
        $body = ":first_name عزیز،\nکیف پول کم است.\nالیت‌زون";

        $this->assertSame("علی عزیز،\nکیف پول کم است.\nالیت‌زون", SmsTemplate::interpolate($body, ['first_name' => 'علی']));
        $this->assertSame("کیف پول کم است.\nالیت‌زون", SmsTemplate::interpolate($body, ['first_name' => '']));
        $this->assertSame("کیف پول کم است.\nالیت‌زون", SmsTemplate::interpolate($body, []));

        $user = User::factory()->create();
        UserProfile::updateOrCreate([UserProfile::USER_ID => $user->id], [UserProfile::NAME => 'سارا']);
        $this->assertSame('سارا', SmsTemplate::firstNameFor($user->mobile));
        $this->assertSame('', SmsTemplate::firstNameFor('09000000000'));
    }
}

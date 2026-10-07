<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Events\Order\OrderPaid;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\Order\SendOrderPaymentNotification;
use App\Models\Agreement;
use App\Models\EmployerEliteYAR;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodiumClient;
use App\Services\Profile\VirtualEmployerAccount;
use Database\Seeders\AgreementSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مدیریت، دورِ هفتم، پرسشِ ۷-۵ (۱۴۰۵/۰۷/۱۵) — `docs/session/MANAGEMENT_ANSWERS_2026-10-07_ROUND7.md`:
 * برای کارفرمای بی‌حساب «یک حساب مجازی باز می‌شود … رفتار سیستم نسبت به این حساب دقیقا مثل یک حساب عادی است. در این حساب
 * الیت یاری که کار خرید را پیش برده به عنوان الیت یار تایید شده ثبت شده است.» پس از پرداخت پیامکی با «لینک تایید حساب و
 * لاگین» می‌رود؛ ورود با آن بی‌مرحله‌ی هویت و نقش است و «کارفرما تنها باید توافقنامه ها را بپذیرد و ثبت نام پایان می یابد».
 */
class VirtualEmployerAccountTest extends TestCase
{
    use DatabaseTransactions;

    private const EMPLOYER_MOBILE = '09121112233';

    private const NATIONAL_CODE = '0012345678';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(AgreementSeeder::class);
        $this->mock(PodiumClient::class, function ($m) {
            $m->shouldReceive('callSc2')->andReturn([
                'hasError' => false,
                'result' => ['matched' => true, 'resultCode' => '1', 'identityInfo' => [
                    'birthDate' => '13700101', 'firstName' => 'رضا', 'lastName' => 'کارفرما',
                ]],
            ]);
        });
        config(['auth.virtual_account.claim_url' => 'https://elitezone.test/account/claim']);
    }

    private function verifiedEliteYAR(): User
    {
        $eliteYar = User::factory()->create([User::IS_SAZE90_USER => true]);
        $eliteYar->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'elite_yar']));

        return $eliteYar;
    }

    /** الیت‌یار هویت را وارد می‌کند (پودیوم) و کارفرما کدِ پیامکی را به او می‌دهد — رضایتِ کارفرما. */
    private function verifyBeneficiary(User $buyer, string $mobile = self::EMPLOYER_MOBILE): User
    {
        Sanctum::actingAs($buyer);
        $id = $this->postJson('/api/beneficiaries/start-verification', [
            'national_code' => self::NATIONAL_CODE, 'birth_date' => '13700101', 'mobile_number' => $mobile,
        ])->assertOk()->json('data.beneficiary_user_id');

        $this->postJson('/api/beneficiaries/confirm-verification', ['beneficiary_user_id' => $id, 'code' => '111111'])->assertOk();

        return User::findOrFail($id);
    }

    #[Test]
    public function a_verified_elite_yar_opens_a_virtual_employer_account_with_themself_as_its_approved_elite_yar(): void
    {
        $eliteYar = $this->verifiedEliteYAR();

        $employer = $this->verifyBeneficiary($eliteYar);

        $this->assertTrue($employer->isVirtualAccount());
        $this->assertSame($eliteYar->id, (int) $employer->{User::CREATED_BY_ELITE_YAR_ID});
        $this->assertTrue($employer->hasRole('employer'), 'نقش کارفرما ثبت می‌شود — مرحله‌ی تعیینِ نقش حذف');
        $this->assertNotNull($employer->employer, 'پروفایلِ کارفرما');
        $this->assertTrue($employer->verifiedEliteYARs()->contains('id', $eliteYar->id), 'الیت‌یارِ تأییدشده‌ی همین کارفرما');
        // همان پرسشی که `CartPaymentLinkService::issue` می‌پرسد — بدونِ آن لینکِ پرداخت برای کارفرمای تازه ۴۲۲ می‌داد.
        $this->assertTrue($eliteYar->associatedEmployers()->where('users.id', $employer->id)->wherePivot('status', 'approved')->exists());
        $this->assertNotNull($employer->{User::NATIONAL_CODE}, 'هویتِ تأییدشده — مرحله‌ی هویت حذف');
        $this->assertDatabaseHas('activity_log', ['subject_id' => $employer->id, 'causer_id' => $eliteYar->id, 'log_name' => 'virtual_account']);
    }

    #[Test]
    public function nothing_is_granted_before_the_employer_gives_the_code(): void
    {
        $eliteYar = $this->verifiedEliteYAR();
        Sanctum::actingAs($eliteYar);

        $id = $this->postJson('/api/beneficiaries/start-verification', [
            'national_code' => self::NATIONAL_CODE, 'birth_date' => '13700101', 'mobile_number' => self::EMPLOYER_MOBILE,
        ])->assertOk()->json('data.beneficiary_user_id');

        $employer = User::findOrFail($id);
        $this->assertFalse($employer->hasRole('employer'));
        $this->assertFalse(EmployerEliteYAR::query()->where(EmployerEliteYAR::EMPLOYER_ID, $id)->exists());
    }

    #[Test]
    public function an_existing_real_account_is_not_taken_over_and_its_profile_is_not_overwritten(): void
    {
        $real = User::factory()->create([User::MOBILE => self::EMPLOYER_MOBILE]);
        UserProfile::updateOrCreate([UserProfile::USER_ID => $real->id], [UserProfile::NAME => 'نامِ خودِ کاربر']);

        $employer = $this->verifyBeneficiary($this->verifiedEliteYAR());

        $this->assertSame($real->id, $employer->id);
        $this->assertFalse($employer->isVirtualAccount());
        $this->assertFalse($employer->hasRole('employer'), 'حسابِ واقعی نقش و رابطه‌اش را خودش تعیین می‌کند');
        $this->assertFalse(EmployerEliteYAR::query()->where(EmployerEliteYAR::EMPLOYER_ID, $real->id)->exists());
        $this->assertSame('نامِ خودِ کاربر', $employer->profile->{UserProfile::NAME}, 'پیش از رضایت، هویتِ ثبت‌شده‌ی کسی بازنویسی نمی‌شود');
    }

    #[Test]
    public function an_elite_yar_on_hold_does_not_open_accounts(): void
    {
        $onHold = User::factory()->create([User::IS_SAZE90_USER => false]);
        $onHold->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'elite_yar']));

        $employer = $this->verifyBeneficiary($onHold);

        $this->assertFalse($employer->isVirtualAccount());
        $this->assertFalse(EmployerEliteYAR::query()->where(EmployerEliteYAR::EMPLOYER_ID, $employer->id)->exists());
    }

    #[Test]
    public function the_claim_link_logs_the_employer_in_without_identity_or_role_steps(): void
    {
        $employer = $this->verifyBeneficiary($this->verifiedEliteYAR());
        $link = app(VirtualEmployerAccount::class)->claimLink($employer);
        $this->assertNotNull($link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $api = "/api/authenticate/claim/{$q['u']}?expires={$q['expires']}&signature={$q['signature']}";
        $this->app['auth']->forgetGuards();

        // GET فقط می‌گوید چه کسی است (پیش‌نمایشِ پیام‌رسان‌ها چیزی را تغییر نمی‌دهد).
        $this->getJson($api)->assertOk()->assertJsonPath('data.claimable', true)->assertJsonPath('data.first_name', 'رضا');
        $this->assertSame(0, $employer->tokens()->count());

        $token = $this->postJson($api, ['device_name' => 'web'])->assertOk()->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertSame(1, $employer->tokens()->count());

        // دست‌کاریِ امضا یا شناسه رد می‌شود.
        $this->postJson("/api/authenticate/claim/{$q['u']}?expires={$q['expires']}&signature=x".$q['signature'])->assertForbidden();
    }

    #[Test]
    public function accepting_the_agreements_finishes_registration_and_retires_the_link(): void
    {
        $employer = $this->verifyBeneficiary($this->verifiedEliteYAR());
        $link = app(VirtualEmployerAccount::class)->claimLink($employer);
        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($employer);
        $pending = $this->getJson('/api/profile/agreements/pending')->json('data.agreements.*.slug');
        $this->assertEqualsCanonicalizing(
            [Agreement::SLUG_ELITEZONE_TERMS, Agreement::SLUG_PRIVACY_POLICY, Agreement::SLUG_EMPLOYER_TERMS], $pending
        );
        foreach ($pending as $slug) {
            $this->postJson("/api/profile/agreements/{$slug}/accept", ['terms_accepted' => true])->assertOk();
        }

        $this->assertFalse($employer->fresh()->isVirtualAccount(), 'از این‌جا به بعد یک اکانتِ واقعی است');
        $this->assertSame('active', $this->getJson('/api/profile')->json('data.account_status'));
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/authenticate/claim/{$q['u']}?expires={$q['expires']}&signature={$q['signature']}")->assertStatus(410);
    }

    #[Test]
    public function after_payment_a_virtual_employer_gets_the_claim_sms_and_a_real_one_does_not(): void
    {
        $eliteYar = $this->verifiedEliteYAR();
        $virtual = $this->verifyBeneficiary($eliteYar);
        $real = User::factory()->create();

        foreach ([[$virtual, 1], [$real, 0]] as [$payer, $expected]) {
            Queue::fake();
            $order = Order::factory()->create([Order::PLACER_USER_ID => $eliteYar->id, Order::BENEFICIARY_USER_ID => $payer->id]);
            $order->forceFill([Order::PAYER_USER_ID => $payer->id])->save();

            app(SendOrderPaymentNotification::class)->handle(new OrderPaid($order));

            $mobile = $payer->mobile;
            $claims = Queue::pushed(SendSmsJob::class, fn (SendSmsJob $job) => (fn () => $this->templateKey === 'employer_account_claim'
                && $this->mobile === $mobile
                && str_contains((string) ($this->params['link'] ?? ''), 'signature='))->call($job));
            $this->assertCount($expected, $claims);
        }
    }

    #[Test]
    public function without_a_claim_page_no_dead_link_is_sent(): void
    {
        config(['auth.virtual_account.claim_url' => '']);
        $eliteYar = $this->verifiedEliteYAR();
        $virtual = $this->verifyBeneficiary($eliteYar);
        $order = Order::factory()->create([Order::PLACER_USER_ID => $eliteYar->id, Order::BENEFICIARY_USER_ID => $virtual->id]);
        $order->forceFill([Order::PAYER_USER_ID => $virtual->id])->save();

        app(SendOrderPaymentNotification::class)->handle(new OrderPaid($order));

        Queue::assertNotPushed(SendSmsJob::class, fn (SendSmsJob $job) => (fn () => $this->templateKey === 'employer_account_claim')->call($job));
    }
}

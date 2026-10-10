<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Models\UserBankAccount;
use App\Services\PodiumServices\CardInquiryService;
use App\Services\PodiumServices\CardToIbanService;
use App\Services\PodiumServices\ShebaInquiryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * ثبتِ دوباره‌ی شبا/کارتی که بک‌آفیس حذف کرده (علی ۱۴۰۵/۰۷/۱۹):
 * - فقط خودِ صاحب، پس از استعلامِ دوباره‌ی مالکیت — همان ردیفِ حذف‌شده بازمی‌گردد (نه ردیفِ تازه).
 * - **شخصِ دیگر هرگز نمی‌فهمد** این شبا ثبت یا حذف شده: استعلام پیش از هر بررسیِ تکراری‌بودن اجرا
 *   می‌شود و او همان پاسخِ عادیِ «به نامِ شما نیست» را می‌گیرد.
 * - ۵۰۰ِ قدیمیِ ثبتِ شبای ثبت‌شده توسطِ کاربرِ دوم (ایندکسِ یکتای سراسری) → همان ۴۲۲ِ بی‌افشا.
 */
class BankAccountReRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    /** @var list<string> */
    private array $inquired = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();

        // استعلامِ واقعی: شبا/کارت فقط برای کدِ ملیِ صاحب «مال شماست».
        $this->mock(ShebaInquiryService::class, fn ($m) => $m->shouldReceive('verify')->andReturnUsing(
            function (string $iban, string $nationalCode) {
                $this->inquired[] = $iban;

                return $nationalCode === $this->owner->national_code;
            }));
        $this->mock(CardInquiryService::class, fn ($m) => $m->shouldReceive('verify')->andReturnUsing(
            function (string $card, string $nationalCode) {
                $this->inquired[] = $card;

                return $nationalCode === $this->owner->national_code;
            }));
        $this->mock(CardToIbanService::class, fn ($m) => $m->shouldReceive('convert')->andReturnNull());
    }

    /** شبای معتبرِ MOD-97 با BBANِ ۲۲ رقمی (کدِ بانکِ ۰۱۷ = ملی). */
    private function iban(string $tail): string
    {
        $bban = '017'.str_pad($tail, 19, '0', STR_PAD_LEFT);
        $remainder = 0;
        foreach (str_split($bban.'182700') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return 'IR'.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bban;
    }

    private function account(User $user, ?string $shaba, ?string $card = null): UserBankAccount
    {
        $account = UserBankAccount::create([
            UserBankAccount::USER_ID => $user->id,
            UserBankAccount::BANK_NAME => 'ملی',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'مالک',
            UserBankAccount::SHABA => $shaba,
            UserBankAccount::CARD_NUMBER => $card,
            UserBankAccount::IS_DEFAULT => false,
        ]);
        $account->forceFill([UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED])->save();

        return $account;
    }

    private function register(User $as, array $body)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/profile/financial/accounts', $body);
    }

    #[Test]
    public function the_owner_gets_the_deleted_shaba_back_after_a_fresh_inquiry(): void
    {
        $shaba = $this->iban('1');
        $deleted = $this->account($this->owner, $shaba);
        $deleted->delete();

        $response = $this->register($this->owner, ['shaba' => $shaba])->assertOk();

        $this->assertSame($deleted->id, (int) $response->json('data.id'), 'همان ردیف بازمی‌گردد، نه ردیفِ تازه.');
        $this->assertSame([$shaba], $this->inquired, 'استعلام دوباره اجرا شد.');
        $restored = UserBankAccount::find($deleted->id);
        $this->assertNotNull($restored);
        $this->assertSame(UserBankAccount::STATUS_APPROVED, $restored->{UserBankAccount::STATUS});
        $this->assertSame(1, UserBankAccount::withTrashed()->where(UserBankAccount::SHABA, $shaba)->count());

        $log = Activity::query()->where('subject_type', User::class)->where('subject_id', $this->owner->id)->latest('id')->first();
        $this->assertNotNull($log, 'بازگردانی ردِ ممیزی دارد.');
        $this->assertSame($deleted->id, $log->properties['bank_account_id']);
        $this->assertSame(substr($shaba, -4), $log->properties['last4']);
    }

    #[Test]
    public function the_owner_gets_the_deleted_card_back(): void
    {
        $deleted = $this->account($this->owner, null, '6037991234567890');
        $deleted->delete();

        $response = $this->register($this->owner, ['card_number' => '6037991234567890'])->assertOk();

        $this->assertSame($deleted->id, (int) $response->json('data.id'));
        $this->assertNull(UserBankAccount::find($deleted->id)?->deleted_at);
    }

    #[Test]
    public function a_stranger_is_told_only_that_it_is_not_in_their_name_whether_the_shaba_is_live_or_deleted(): void
    {
        $stranger = User::factory()->create();
        $live = $this->iban('2');
        $this->account($this->owner, $live);
        $gone = $this->iban('3');
        $this->account($this->owner, $gone)->delete();
        $never = $this->iban('4');

        $answers = [];
        foreach ([$live, $gone, $never] as $shaba) {
            $answers[] = $this->register($stranger, ['shaba' => $shaba])
                ->assertStatus(422)
                ->assertJsonPath('errors.shaba.0', __('bank.shaba_mismatch'))
                ->json();
        }

        $this->assertSame($answers[2], $answers[0], 'ثبت‌شده و هرگز‌ثبت‌نشده باید یک پاسخ بگیرند.');
        $this->assertSame($answers[2], $answers[1], 'حذف‌شده و هرگز‌ثبت‌نشده باید یک پاسخ بگیرند.');
        $this->assertNotNull(UserBankAccount::withTrashed()->where(UserBankAccount::SHABA, $gone)->first()?->deleted_at);
    }

    #[Test]
    public function the_inquiry_runs_before_the_duplicate_check(): void
    {
        $shaba = $this->iban('5');
        $this->account($this->owner, $shaba);

        $this->register($this->owner, ['shaba' => $shaba])
            ->assertStatus(422)
            ->assertJsonPath('errors.shaba.0', __('auth.shaba_unique'));

        $this->assertSame([$shaba], $this->inquired);
    }

    /** پیش از این: درج روی ایندکسِ یکتای سراسری ۵۰۰ می‌داد (استعلام برای کدِ ملیِ دیگری هم «بله» گفته بود). */
    #[Test]
    public function a_second_user_whose_inquiry_passes_gets_the_ordinary_422_not_a_500(): void
    {
        $shaba = $this->iban('6');
        $this->account($this->owner, $shaba);
        $twin = User::factory()->create();
        $this->owner = $twin;   // استعلام حالا برای کاربرِ دوم «بله» می‌گوید

        $this->register($twin, ['shaba' => $shaba])
            ->assertStatus(422)
            ->assertJsonPath('errors.shaba.0', __('bank.shaba_mismatch'));

        $this->assertSame(1, UserBankAccount::withTrashed()->where(UserBankAccount::SHABA, $shaba)->count());
    }

    #[Test]
    public function a_deleted_shaba_is_never_restored_to_someone_else(): void
    {
        $shaba = $this->iban('7');
        $deleted = $this->account($this->owner, $shaba);
        $deleted->delete();
        $twin = User::factory()->create();
        $this->owner = $twin;

        $this->register($twin, ['shaba' => $shaba])->assertStatus(422);

        $row = UserBankAccount::withTrashed()->find($deleted->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertNotSame($twin->id, (int) $row->{UserBankAccount::USER_ID});
    }

    #[Test]
    public function a_shaba_sent_with_a_card_is_inquired_too(): void
    {
        $stranger = User::factory()->create();
        $theirs = $this->iban('8');

        // کارت را مالکِ واقعی ندارد؛ شبای کسِ دیگر هم نباید بدونِ استعلام بنشیند.
        $this->owner = $stranger;
        $this->register($stranger, ['card_number' => '6037990000000001', 'shaba' => $theirs])->assertOk();
        $this->assertContains($theirs, $this->inquired, 'شبای همراهِ کارت هم استعلام می‌شود.');
    }
}

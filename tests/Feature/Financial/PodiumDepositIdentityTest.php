<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Models\PodiumDepositRecord;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ShenasehVarizService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * وقتی واریز IBAN ندارد، تنها چیزی که می‌گوید پول مالِ کیست، **کد ملیِ داخلِ
 * شناسه‌ی واریز** است — و `users.national_code` یکتا نیست (یکتایی فقط روی
 * `user_profiles.national_code` است).
 *
 * ‏`User::where('national_code', …)->first()` یعنی وقتی دو ردیف با یک کد ملی
 * وجود داشت، واریز به هرکدام که اول می‌آمد می‌نشست: پولِ یک نفر در کیف‌پولِ
 * دیگری، بدونِ هیچ خطا و هیچ لاگی.
 *
 * حالا داده‌ی مبهم متوقف می‌شود و برای تأییدِ دستیِ ادمین پارک می‌گردد — همان
 * اصلی که گاردهای `official_price` و `weight_before_scale` دارند.
 */
class PodiumDepositIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private const NATIONAL_CODE = '0071234567';

    private const AMOUNT = 5_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        // شارژِ موفق SMS تشکر می‌فرستد؛ صفِ واقعی اینجا موضوعِ تست نیست.
        Queue::fake();
    }

    /** ردیفِ Statement بدونِ هیچ metadataیِ IBAN — همان حالتی که fallback را فعال می‌کند. */
    private function statementWithoutIban(string $docNumber): array
    {
        return [
            'DocNumber' => $docNumber,
            'CreditorAmount' => self::AMOUNT,
            'DebtorAmount' => 0,
            'Date' => '1405/05/10',
            'Time' => '10:00.00.000',
            'TransferMoneyBillNumber' => app(ShenasehVarizService::class)->generate(self::NATIONAL_CODE),
        ];
    }

    private function makeUserWithWallet(): User
    {
        $user = User::factory()->create();
        $user->forceFill([User::NATIONAL_CODE => self::NATIONAL_CODE])->save();

        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        return $user;
    }

    #[Test]
    public function a_national_code_shared_by_two_users_parks_the_deposit_instead_of_guessing(): void
    {
        $first = $this->makeUserWithWallet();
        $second = $this->makeUserWithWallet();

        app(ProcessPodiumDepositAction::class)->execute($this->statementWithoutIban('AMBIGUOUS-1'));

        // پیش از اصلاح، `->first()` کیف‌پولِ $first را شارژ می‌کرد.
        $this->assertSame(0.0, (float) $first->wallet()->first()->balance);
        $this->assertSame(0.0, (float) $second->wallet()->first()->balance);

        $record = PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, 'AMBIGUOUS-1')->first();

        $this->assertNotNull($record, 'واریزِ مبهم باید برای تأییدِ دستی پارک شود، نه اینکه بی‌صدا رها گردد.');
        $this->assertStringContainsString('چند کاربر', (string) $record->{PodiumDepositRecord::NOTE});
    }

    #[Test]
    public function a_unique_national_code_still_credits_the_wallet(): void
    {
        $user = $this->makeUserWithWallet();

        app(ProcessPodiumDepositAction::class)->execute($this->statementWithoutIban('UNIQUE-1'));

        // گاردِ ابهام نباید مسیرِ سالم را ببندد.
        $this->assertSame((float) self::AMOUNT, (float) $user->wallet()->first()->balance);
    }

    #[Test]
    public function an_unknown_national_code_keeps_its_own_distinct_note(): void
    {
        // «کسی نیست» و «چند نفرند» دو مشکلِ متفاوت با دو راه‌حلِ متفاوت‌اند و
        // ادمین باید در پنل بتواند تشخیصشان بدهد.
        app(ProcessPodiumDepositAction::class)->execute($this->statementWithoutIban('MISSING-1'));

        $record = PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, 'MISSING-1')->first();

        $this->assertNotNull($record);
        $this->assertStringContainsString('یافت نشد', (string) $record->{PodiumDepositRecord::NOTE});
    }
}

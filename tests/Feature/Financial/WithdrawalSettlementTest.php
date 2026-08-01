<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessTransactionAction;
use App\Actions\Financial\RequestWithdrawalAction;
use App\Actions\Financial\SettleWithdrawalAction;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the automatic Podium withdrawal settlement flow.
 *
 * No real Podium call is made: Http::fake() stubs doServiceCall and Queue::fake()
 * absorbs the queued SMS-notification listener. DatabaseTransactions rolls back.
 */
class WithdrawalSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private const IBAN = 'IR050560611828005105065001';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Enable the (otherwise fail-safe) settlement service with sandbox-like creds.
        config([
            'podium.settlement.enabled' => true,
            'podium.settlement.sc_product_id' => '158267',
            'podium.settlement.sc_api_key' => 'test-key',
            'podium.settlement.satna_threshold' => 1_000_000_000,
            'podium.settlement.detail_type' => 'DETAIL_TYPE_GENERAL_COST',
            'podium.settlement.auto_on_request' => false,
        ]);
    }

    private function fakePodiumSuccess(string $toolCode = 'SETTLEMENT_TOOL_PAYA'): void
    {
        Http::fake([
            '*doServiceCall*' => Http::response([
                'hasError' => false,
                'referenceNumber' => '3582715528',
                'errorCode' => 0,
                'result' => [
                    'id' => 4697990,
                    'status' => 'SETTLEMENT_WAIT_FOR_SENDING',
                    'toolCode' => $toolCode,
                ],
            ], 200),
        ]);
    }

    private function makeUserWithWallet(int $balance = 0): User
    {
        $user = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $user->id,
            Wallet::BALANCE => $balance,
            Wallet::BALANCE_HOLD => 0,
        ]);

        return $user;
    }

    private function approvedBankAccount(User $user): UserBankAccount
    {
        $acc = UserBankAccount::create([
            UserBankAccount::USER_ID => $user->id,
            UserBankAccount::BANK_NAME => 'بانک پاسارگاد',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'Navid Mirtorabi',
            UserBankAccount::SHABA => self::IBAN,
            UserBankAccount::CARD_NUMBER => '6037997432369115',
        ]);
        $acc->forceFill([UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED])->save();

        return $acc;
    }

    private function pendingWithdraw(User $user, int $amount): WalletTransaction
    {
        return $user->wallet->transactions()->create([
            WalletTransaction::AMOUNT => $amount,
            WalletTransaction::TYPE => WalletTransaction::TYPE_WITHDRAW,
            WalletTransaction::STATUS => WalletTransaction::STATUS_PENDING,
            WalletTransaction::DESCRIPTION => 'test withdraw',
            WalletTransaction::META => [
                'shaba' => self::IBAN,
                'holder_name' => 'Navid Mirtorabi',
                'settlement' => ['state' => 'awaiting_approval'],
            ],
        ]);
    }

    // ─── Request side ───────────────────────────────────────────────────────────

    #[Test]
    public function requesting_a_withdrawal_debits_the_wallet_and_creates_a_pending_transaction(): void
    {
        $user = $this->makeUserWithWallet(balance: 1_000_000);
        $account = $this->approvedBankAccount($user);

        $tx = app(RequestWithdrawalAction::class)->execute($user, 500_000, $account->id);

        $this->assertSame(WalletTransaction::STATUS_PENDING, $tx->status);
        $this->assertSame('awaiting_approval', $tx->meta['settlement']['state']);
        $this->assertEqualsWithDelta(500_000, (float) $user->wallet->fresh()->balance, 0.5);
        Http::assertNothingSent();
    }

    // ─── Admin-approved settlement ───────────────────────────────────────────────

    #[Test]
    public function admin_confirm_settles_via_podium_and_marks_the_transaction_confirmed(): void
    {
        $this->fakePodiumSuccess();

        $user = $this->makeUserWithWallet(balance: 0); // money already debited at request time
        $tx = $this->pendingWithdraw($user, 500_000);
        $admin = User::factory()->create();

        $settled = app(ProcessTransactionAction::class)->execute(
            $tx, WalletTransaction::STATUS_CONFIRMED, 'ok', $admin
        );

        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $settled->status);
        $this->assertSame('sent', $settled->meta['settlement']['state']);
        $this->assertSame('3582715528', $settled->meta['settlement']['reference_number']);
        $this->assertEqualsWithDelta(0, (float) $user->wallet->fresh()->balance, 0.5);

        Http::assertSent(function ($request) use ($tx) {
            return str_contains($request->url(), 'doServiceCall')
                && $request['toolCode'] === 'SETTLEMENT_TOOL_PAYA'
                && $request['toolId'] === self::IBAN
                && $request['uniqueId'] === 'wd-'.$tx->id
                && (string) $request['amount'] === '500000';
        });
    }

    #[Test]
    public function large_amounts_settle_via_satna(): void
    {
        $this->fakePodiumSuccess('SETTLEMENT_TOOL_SATNA');

        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 1_500_000_000); // >= satna_threshold

        app(SettleWithdrawalAction::class)->execute($tx);

        Http::assertSent(fn ($request) => $request['toolCode'] === 'SETTLEMENT_TOOL_SATNA');
    }

    // ─── Failure / safety ────────────────────────────────────────────────────────

    #[Test]
    public function a_podium_business_error_leaves_the_transaction_pending_and_retryable(): void
    {
        Http::fake([
            '*doServiceCall*' => Http::response([
                'hasError' => true,
                'message' => 'مبلغ قابل برداشت کافی نیست',
                'errorCode' => 123,
            ], 200),
        ]);

        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);

        try {
            app(SettleWithdrawalAction::class)->execute($tx);
            $this->fail('Expected a RuntimeException on Podium business error.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $tx->refresh();
        $this->assertSame(WalletTransaction::STATUS_PENDING, $tx->status, 'Must stay pending so admin can retry');
        $this->assertSame('failed', $tx->meta['settlement']['state']);
        $this->assertEqualsWithDelta(0, (float) $user->wallet->fresh()->balance, 0.5, 'Money is neither lost nor refunded automatically');
    }

    #[Test]
    public function settlement_fails_safe_when_not_configured(): void
    {
        config(['podium.settlement.enabled' => false]);
        Http::fake();

        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);

        try {
            app(SettleWithdrawalAction::class)->execute($tx);
            $this->fail('Expected a RuntimeException when settlement is not configured.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(WalletTransaction::STATUS_PENDING, $tx->fresh()->status);
        Http::assertNothingSent();
    }

    // ─── Re-entrancy: the claim must actually gate ───────────────────────────────

    #[Test]
    public function a_second_settlement_while_the_first_is_in_flight_is_refused(): void
    {
        // بازورودِ **واقعی**: در همان لحظه‌ای که تماس با پادیوم در جریان است، یک
        // تلاشِ دوم روی همان ردیف انجام می‌شود.
        //
        // تا پیش از این، فاز A فقط `status !== PENDING` را می‌سنجید — و ردیف تا
        // پایانِ فاز C عمداً PENDING می‌ماند. پس هر دو تلاش رد می‌شدند و هر دو به
        // پادیوم POST می‌کردند؛ تنها چیزی که جلوی واریزِ دوباره را می‌گرفت dedupِ
        // سمتِ پادیوم روی `uniqueId` بود، نه کدِ ما.
        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);

        $reentry = null;
        $attempted = false;

        Http::fake(function () use ($tx, &$reentry, &$attempted) {
            // فقط یک بار بازورود — بدون این گارد، کدِ *معیوب* بی‌نهایت بازگشتی
            // می‌شد (هر تماس یک تسویهٔ تازه می‌ساخت) و کلِ اجرای تست را با
            // memory exhaustion می‌کشت به‌جای اینکه تمیز fail شود.
            if (! $attempted) {
                $attempted = true;
                try {
                    app(SettleWithdrawalAction::class)->execute(WalletTransaction::findOrFail($tx->id));
                    $reentry = 'allowed';
                } catch (\RuntimeException $e) {
                    $reentry = $e->getMessage();
                }
            }

            return Http::response([
                'hasError' => false,
                'referenceNumber' => '3582715528',
                'errorCode' => 0,
                'result' => ['id' => 4697990, 'status' => 'SETTLEMENT_WAIT_FOR_SENDING', 'toolCode' => 'SETTLEMENT_TOOL_PAYA'],
            ], 200);
        });

        app(SettleWithdrawalAction::class)->execute($tx);

        $this->assertSame(__('wallet.settlement.in_progress'), $reentry, 'تلاشِ هم‌زمانِ دوم باید رد شود.');

        // و مهم‌تر: فقط **یک** درخواست به پادیوم رفته باشد.
        Http::assertSentCount(1);
        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $tx->fresh()->status);
    }

    #[Test]
    public function a_stale_claim_does_not_lock_the_row_forever(): void
    {
        // اگر پروسه بینِ تماسِ بانکی و ثبتِ نتیجه کرش کند، ردیف با state='sending'
        // می‌ماند. بدون TTL هیچ ادمینی دیگر نمی‌توانست تسویه را تمام کند.
        $this->fakePodiumSuccess();

        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);

        $ttl = (int) config('podium.settlement.claim_ttl_minutes', 15);
        $meta = $tx->meta;
        $meta['settlement'] = ['state' => 'sending', 'claimed_at' => now()->subMinutes($ttl + 1)->toIso8601String()];
        $tx->update([WalletTransaction::META => $meta]);

        app(SettleWithdrawalAction::class)->execute($tx);

        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $tx->fresh()->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_failed_attempt_is_retryable_without_waiting_for_the_ttl(): void
    {
        // شکستِ صریح یعنی هیچ تماسی در جریان نیست — تلاش مجدد نباید منتظر TTL بماند.
        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);

        // یک fake با دو پاسخِ پشتِ‌هم: تلاشِ اول شکست، تلاشِ دوم موفق. (فراخوانِ
        // دوبارهٔ `Http::fake()` استابِ قبلی را جایگزین نمی‌کند، ادغام می‌کند.)
        Http::fake(['*doServiceCall*' => Http::sequence()
            ->push(['hasError' => true, 'message' => 'خطا', 'errorCode' => 1], 200)
            ->push([
                'hasError' => false,
                'referenceNumber' => '3582715528',
                'errorCode' => 0,
                'result' => ['id' => 4697990, 'status' => 'SETTLEMENT_WAIT_FOR_SENDING', 'toolCode' => 'SETTLEMENT_TOOL_PAYA'],
            ], 200),
        ]);

        try {
            app(SettleWithdrawalAction::class)->execute($tx);
            $this->fail('Expected a RuntimeException on the first attempt.');
        } catch (\RuntimeException) {
            // expected
        }

        $tx->refresh();
        $this->assertSame('failed', $tx->meta['settlement']['state']);
        $this->assertNull($tx->meta['settlement']['claimed_at'] ?? null, 'claim باید با شکست آزاد شود.');

        app(SettleWithdrawalAction::class)->execute($tx);

        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $tx->fresh()->status);
    }

    #[Test]
    public function confirming_an_already_settled_withdrawal_is_idempotent(): void
    {
        $this->fakePodiumSuccess();

        $user = $this->makeUserWithWallet(balance: 0);
        $tx = $this->pendingWithdraw($user, 500_000);
        $tx->update([WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED]);

        app(SettleWithdrawalAction::class)->execute($tx);

        Http::assertNothingSent();
    }

    // ─── Reject path unchanged ───────────────────────────────────────────────────

    #[Test]
    public function admin_reject_refunds_the_wallet_and_never_calls_podium(): void
    {
        Http::fake();

        $user = $this->makeUserWithWallet(balance: 0); // debited at request time
        $tx = $this->pendingWithdraw($user, 500_000);
        $admin = User::factory()->create();

        app(ProcessTransactionAction::class)->execute(
            $tx, WalletTransaction::STATUS_REJECTED, 'rejected', $admin
        );

        $this->assertSame(WalletTransaction::STATUS_REJECTED, $tx->fresh()->status);
        $this->assertEqualsWithDelta(500_000, (float) $user->wallet->fresh()->balance, 0.5, 'Rejected withdrawal is refunded');
        Http::assertNothingSent();
    }
}

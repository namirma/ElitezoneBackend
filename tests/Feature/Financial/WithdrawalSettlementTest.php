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

<?php

namespace Tests\Feature\Audit;

use App\Actions\Financial\ProcessTransactionAction;
use App\Jobs\Notification\SendBulkNotificationJob;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Regression tests for the "risky" batch (R1 idempotency fallback, R4 dedup,
 * R5 verified_amount, R15 payable-status alignment).
 *
 * @group audit-fixes
 */
class RiskyFixesTest extends TestCase
{
    use DatabaseTransactions;

    /** R15 — both payment paths share Order::PAYABLE_STATUSES incl. RESERVED. */
    public function test_payable_statuses_are_aligned_and_include_reserved(): void
    {
        $this->assertContains(Order::STATUS_PENDING, Order::PAYABLE_STATUSES);
        $this->assertContains(Order::STATUS_RESERVED, Order::PAYABLE_STATUSES);
    }

    /** R4 — SendSmsJob must not send the same message twice within the dedupe window. */
    public function test_sms_job_dedupes_identical_message_within_window(): void
    {
        Cache::flush();

        $mock = Mockery::mock(SmsService::class);
        $mock->shouldReceive('send')->once(); // only the FIRST job actually sends
        $this->app->instance(SmsService::class, $mock);

        (new SendSmsJob('09120000000', 'code 111111'))->handle($mock);
        (new SendSmsJob('09120000000', 'code 111111'))->handle($mock); // duplicate → skipped

        $this->assertTrue(true);
    }

    /** R4 — bulk notification is idempotent on re-run (same batch_id + insertOrIgnore). */
    public function test_bulk_notification_is_idempotent_on_rerun(): void
    {
        $u = User::factory()->create();

        $job = new SendBulkNotificationJob('subject', 'body', [$u->id], null);
        $job->handle();
        $job->handle(); // simulate retry with the SAME batch_id

        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $u->id)
            ->where(UserNotification::BATCH_ID, $job->batchId)->count());
    }

    /** R5 — admin-verified deposit amount is credited instead of the user-declared one. */
    public function test_verified_amount_overrides_declared_deposit_amount(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $admin = User::factory()->create();

        $tx = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'amount' => 1_000_000,               // user-declared
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
            'description' => 'deposit',
        ]);

        app(ProcessTransactionAction::class)
            ->execute($tx, WalletTransaction::STATUS_CONFIRMED, 'verified from receipt', $admin, 750_000);

        $this->assertEqualsWithDelta(750_000, (float) $wallet->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(750_000, (float) $tx->fresh()->amount, 0.01);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

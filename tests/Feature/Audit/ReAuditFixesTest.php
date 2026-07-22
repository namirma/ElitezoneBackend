<?php

namespace Tests\Feature\Audit;

use App\Jobs\Notification\SendInviteSmsJob;
use App\Jobs\Notification\SendSmsJob;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the final re-audit batch (safe fixes).
 *
 * @group audit-fixes
 */
class ReAuditFixesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * SmsService: when TSMS is unconfigured (test/staging), send() is a no-op SUCCESS
     * (returns true) — NOT a delivery failure. This is the contract the SMS jobs rely on
     * (they now retry/throw on `false`); returning false here would make every OTP flow
     * throw in environments without an SMS gateway.
     */
    public function test_sms_service_unconfigured_is_noop_success(): void
    {
        // In the testing env services.tsms.link/number are empty.
        $result = app(SmsService::class)->send('09120000000', 'hello');

        $this->assertTrue($result, 'unconfigured SmsService::send must return true (no-op), not false');
    }

    /**
     * Queue retry_after must exceed the longest job timeout (GenerateCsvReportJob = 1800s),
     * otherwise a long job is re-delivered to a second worker and runs concurrently.
     */
    public function test_queue_retry_after_exceeds_longest_job_timeout(): void
    {
        $this->assertGreaterThan(
            1800,
            (int) config('queue.connections.database.retry_after'),
            'database.retry_after must be > the longest job timeout (1800s)'
        );
    }

    /**
     * SMS jobs must no longer cap exceptions at 1 (which neutralised tries=3).
     */
    public function test_sms_jobs_allow_full_retries(): void
    {
        foreach ([SendSmsJob::class, SendInviteSmsJob::class] as $jobClass) {
            $this->assertFalse(
                property_exists($jobClass, 'maxExceptions'),
                "{$jobClass} should not declare maxExceptions (it neutralises tries)"
            );
        }
    }
}

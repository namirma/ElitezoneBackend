<?php

namespace Tests\Feature\Events;

use App\Events\Cart\CartItemChanged;
use App\Events\Financial\TransactionProcessed;
use App\Events\Financial\WalletFunded;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPaid;
use App\Events\Order\OrderPlaced;
use App\Events\User\CustomerSegmentChanged;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

/**
 * Guards against the regression where an event declared
 * `implements ShouldDispatchAfterCommit` but lacked the import, so PHP resolved
 * the name to the local App\Events\* namespace (non-existent interface) and the
 * class fatal-errored the moment it was dispatched (e.g. removing a cart item).
 *
 * Every domain event must implement the REAL framework contract so its queued
 * listeners run only after the surrounding DB transaction commits.
 */
class EventsDispatchAfterCommitTest extends TestCase
{
    public static function domainEvents(): array
    {
        return [
            [CartItemChanged::class],
            [OrderPlaced::class],
            [OrderCancelled::class],
            [OrderPaid::class],
            [TransactionProcessed::class],
            [WalletFunded::class],
            [CustomerSegmentChanged::class],
        ];
    }

    /**
     * @test
     *
     * @dataProvider domainEvents
     */
    public function it_implements_the_real_after_commit_contract(string $eventClass): void
    {
        $this->assertTrue(
            class_exists($eventClass),
            "Event {$eventClass} should be autoloadable",
        );

        $this->assertContains(
            ShouldDispatchAfterCommit::class,
            class_implements($eventClass),
            "{$eventClass} must implement the framework ShouldDispatchAfterCommit contract (check the `use` import).",
        );
    }
}

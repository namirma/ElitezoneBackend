<?php

namespace Tests\Feature\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
            [\App\Events\Cart\CartItemChanged::class],
            [\App\Events\Order\OrderPlaced::class],
            [\App\Events\Order\OrderCancelled::class],
            [\App\Events\Order\OrderPaid::class],
            [\App\Events\Financial\TransactionProcessed::class],
            [\App\Events\Financial\WalletFunded::class],
            [\App\Events\User\CustomerSegmentChanged::class],
        ];
    }

    #[Test]
    #[DataProvider('domainEvents')]
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

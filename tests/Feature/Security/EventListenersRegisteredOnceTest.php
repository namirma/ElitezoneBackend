<?php

namespace Tests\Feature\Security;

use App\Events\Cart\CartItemChanged;
use App\Events\Order\OrderPaid;
use App\Providers\EventServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هر listener دقیقاً یک بار ثبت می‌شود.
 *
 * روی L13 `Application::configure()` خودش `withEvents()` را با discovery روشن صدا
 * می‌زد؛ همان listenerهای `$listen` یک بار دیگر از اسکنِ `app/Listeners` (به شکلِ
 * `Class@handle`) ثبت می‌شدند و یک `OrderPaid` هر پیامک را دو بار صف می‌کرد.
 * `bootstrap/app.php` حالا `withEvents(discover: false)` دارد.
 *
 * مقایسه روی نامِ کلاس است نه تعداد: `EventServiceProvider::boot()` روی رویدادهای
 * `EventCatalog` یک `SendCustomSmsForEvent` هم می‌گذارد که در `$listen` نیست.
 */
class EventListenersRegisteredOnceTest extends TestCase
{
    #[Test]
    public function order_paid_listeners_are_registered_once(): void
    {
        $this->assertListenedOnce(OrderPaid::class);
    }

    #[Test]
    public function cart_item_changed_listeners_are_registered_once(): void
    {
        $this->assertListenedOnce(CartItemChanged::class);
    }

    #[Test]
    public function email_verification_is_wired_exactly_once(): void
    {
        // هر دو EventServiceProvider (اپ و فریم‌ورک) `configureEmailVerification()` را
        // اجرا می‌کنند و هر کدام فقط `$listen` خودش را می‌بیند.
        $this->assertCount(1, Event::getListeners(Registered::class));
    }

    #[Test]
    public function no_app_event_is_registered_twice(): void
    {
        foreach (array_keys($this->listenMap()) as $event) {
            $this->assertListenedOnce($event);
        }
    }

    private function assertListenedOnce(string $event): void
    {
        $names = array_map(
            fn ($listener) => is_string($listener) ? Str::before($listener, '@') : (is_array($listener) ? $listener[0] : $listener),
            Event::getRawListeners()[$event] ?? [],
        );

        foreach ($this->listenMap()[$event] ?? [] as $listener) {
            $this->assertContains($listener, $names, "{$listener} is not registered for {$event}");
        }

        $this->assertSame(
            count($names),
            count(array_unique($names, SORT_REGULAR)),
            "{$event} has a listener registered more than once — is event discovery back on?"
        );
    }

    /** @return array<class-string, array<int, class-string>> */
    private function listenMap(): array
    {
        return $this->app->getProvider(EventServiceProvider::class)->listens();
    }
}

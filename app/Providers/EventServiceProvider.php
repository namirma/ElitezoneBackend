<?php

namespace App\Providers;

use App\Events\Cart\CartItemChanged;
use App\Events\Financial\TransactionProcessed;
use App\Events\Financial\WalletFunded;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPaid;
use App\Events\Order\OrderPlaced;
use App\Events\User\CustomerSegmentChanged;
use App\Listeners\Cart\LogCartEvent;
use App\Listeners\Financial\SendTransactionNotification;
use App\Listeners\Financial\SendWalletFundingNotification;
use App\Listeners\Notification\SendCustomSmsForEvent;
use App\Listeners\Order\SendOrderCancellationNotification;
use App\Listeners\Order\SendOrderPaymentNotification;
use App\Listeners\Order\SendOrderPlacedNotification;
use App\Listeners\Order\SendSupplierOrderNotification;
use App\Listeners\User\SendWinBackOffer;
use App\Sms\EventCatalog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        OrderPaid::class => [
            SendOrderPaymentNotification::class,
            SendSupplierOrderNotification::class,
        ],
        WalletFunded::class => [
            SendWalletFundingNotification::class,
        ],
        TransactionProcessed::class => [
            SendTransactionNotification::class,
        ],
        OrderCancelled::class => [
            SendOrderCancellationNotification::class,
        ],
        OrderPlaced::class => [
            SendOrderPlacedNotification::class,
        ],
        CartItemChanged::class => [
            LogCartEvent::class,
        ],
        CustomerSegmentChanged::class => [
            SendWinBackOffer::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        // پیامک‌های سفارشیِ ادمین: یک لیسنر روی هر رویدادِ فهرست‌شده. از خودِ
        // فهرست ثبت می‌شود تا `EventCatalog` تنها منبعِ «کدام رویدادها» بماند —
        // ورودیِ تازه در فهرست بدونِ ویرایشِ این‌جا شنیده می‌شود.
        foreach (EventCatalog::events() as $event) {
            Event::listen($event, SendCustomSmsForEvent::class);
        }
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

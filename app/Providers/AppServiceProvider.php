<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\ProductPriceObserver;
use App\Observers\TicketObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

use function in_array;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // env() is intentionally NOT used here — use config() so that
        // `php artisan config:cache` is respected in production.
        if (! config('app.debug')) {
            URL::forceScheme('https');
        }

        if (config('app.debug')) {
            URL::forceScheme('http');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', function (?User $user = null) {
            if (app()->environment('local')) {
                return true;
            }

            // Authorised emails are set via APP_API_DOCS_EMAILS= in .env
            // (comma-separated). Managed in config/app.php → api_docs_emails.
            $allowed = (array) config('app.api_docs_emails', []);

            return $user && in_array($user->email, $allowed, strict: true);
        });
        ProductPrice::observe(ProductPriceObserver::class);
        Order::observe(OrderObserver::class);
        Ticket::observe(TicketObserver::class);

        if (app()->isProduction() && config('queue.default') === 'sync') {
            Log::critical('QUEUE_CONNECTION=sync in production — queued listeners run inside the HTTP request and block responses.');
        }
        if (app()->isProduction() && config('cache.default') === 'file') {
            Log::critical('CACHE_DRIVER=file in production — OTP lockout and idempotency keys are not shared across servers.');
        }
    }
}

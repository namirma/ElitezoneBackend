<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Models\ProductPrice;
use App\Observers\OrderObserver;
use App\Observers\ProductPriceObserver;
use function in_array;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (!env("APP_DEBUG")) {
            URL::forceScheme('https');
        }

        if (env("APP_DEBUG")) {
            URL::forceScheme("http");
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
            return $user && in_array($user->email, [
                'navidm632@gmail.com',
                //'zarsanjsajad@gmail.com',
            ]);
        });
        ProductPrice::observe(ProductPriceObserver::class);
        Order::observe(OrderObserver::class);
    }
}

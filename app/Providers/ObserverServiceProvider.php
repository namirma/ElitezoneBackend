<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Observers\OrderObserver;
use App\Observers\ProductPriceObserver;

class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ProductPrice::observe(ProductPriceObserver::class);
        Order::observe(OrderObserver::class);
    }
}

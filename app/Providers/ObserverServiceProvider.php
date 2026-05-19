<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\ProductPrice;
use App\Observers\OrderObserver;
use App\Observers\ProductPriceObserver;
use Illuminate\Support\ServiceProvider;

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

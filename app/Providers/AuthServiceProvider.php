<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Order::class           => \App\Policies\OrderPolicy::class,
        \App\Models\Cart::class            => \App\Policies\CartPolicy::class,
        \App\Models\Address::class         => \App\Policies\AddressPolicy::class,
        \App\Models\Wishlist::class        => \App\Policies\WishlistPolicy::class,
        \App\Models\UserBankAccount::class => \App\Policies\UserBankAccountPolicy::class,
        \App\Models\Factory::class         => \App\Policies\FactoryPolicy::class,
        \App\Models\FactoryLocation::class => \App\Policies\FactoryLocationPolicy::class,
        \App\Models\ProductPrice::class    => \App\Policies\ProductPricePolicy::class,
        \App\Models\ShippingRate::class    => \App\Policies\ShippingRatePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}

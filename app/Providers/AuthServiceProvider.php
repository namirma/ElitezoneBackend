<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\ShippingRate;
use App\Models\UserBankAccount;
use App\Models\Wishlist;
use App\Policies\AddressPolicy;
use App\Policies\CartPolicy;
use App\Policies\FactoryLocationPolicy;
use App\Policies\FactoryPolicy;
use App\Policies\FactoryStockPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPricePolicy;
use App\Policies\ShippingRatePolicy;
use App\Policies\UserBankAccountPolicy;
use App\Policies\WishlistPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Order::class => OrderPolicy::class,
        Cart::class => CartPolicy::class,
        Address::class => AddressPolicy::class,
        Wishlist::class => WishlistPolicy::class,
        UserBankAccount::class => UserBankAccountPolicy::class,
        Factory::class => FactoryPolicy::class,
        FactoryLocation::class => FactoryLocationPolicy::class,
        FactoryStock::class => FactoryStockPolicy::class,
        ProductPrice::class => ProductPricePolicy::class,
        ShippingRate::class => ShippingRatePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}

<?php

namespace App\Providers;

use App\Contracts\TicketReplyRepositoryInterface;
use App\Contracts\TicketRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Eloquent\OrderRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\TicketReplyRepository;
use App\Repositories\TicketRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // اتصال اینترفیس محصول به ریپازیتوری محصول
        $this->app->bind(
            ProductRepositoryInterface::class,
            ProductRepository::class
        );

        // اتصال اینترفیس سفارش به ریپازیتوری سفارش
        $this->app->bind(
            OrderRepositoryInterface::class,
            OrderRepository::class
        );

        $this->app->bind(TicketRepositoryInterface::class, TicketRepository::class);
        $this->app->bind(TicketReplyRepositoryInterface::class, TicketReplyRepository::class);

    }
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

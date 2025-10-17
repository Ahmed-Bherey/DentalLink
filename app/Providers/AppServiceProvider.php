<?php

namespace App\Providers;

use App\Models\Financial\Order;
use App\Models\Financial\Payment;
use App\Models\Shopping\FavoriteProduct;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Relation::morphMap([
        'order' => Order::class,
        'payment' => Payment::class,
        'favoriteProduct' => FavoriteProduct::class,
    ]);
    }
}

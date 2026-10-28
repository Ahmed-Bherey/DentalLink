<?php

namespace App\Providers;

use App\Models\Financial\Order;
use App\Models\Financial\Payment;
use App\Models\Shopping\FavoriteProduct;
use App\Support\DatabaseSafety;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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

        $this->guardDestructiveDatabaseCommands();
    }

    /**
     * Refuse destructive artisan commands aimed at a non-test database.
     *
     * Hooked to CommandStarting rather than to individual command classes so it
     * covers every invocation path — the terminal, `Artisan::call()`, and the
     * `migrate:fresh` that RefreshDatabase runs at the start of a test suite.
     * The check happens before the command's handle() method, so nothing is
     * dropped before the refusal.
     *
     * See App\Support\DatabaseSafety for why this cannot rely on APP_ENV.
     */
    private function guardDestructiveDatabaseCommands(): void
    {
        Event::listen(function (CommandStarting $event) {
            DatabaseSafety::guardCommand(
                $event->command,
                // `--database=` wins when present, because that is the connection
                // the command itself will use. Fall back to the default.
                $event->input->hasParameterOption('--database')
                    ? $event->input->getParameterOption('--database')
                    : config('database.default'),
                fn (string $connection) => config("database.connections.{$connection}.database")
            );
        });
    }
}

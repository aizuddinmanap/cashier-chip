<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Console\Commands\WebhookCommand;
use Aizuddinmanap\CashierChip\Http\Controllers\WebhookController;
use Aizuddinmanap\CashierChip\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChipServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerResources();
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register new configuration (preferred)
        $this->mergeConfigFrom(
            __DIR__.'/../config/cashier.php',
            'cashier'
        );

        // Register legacy configuration for backward compatibility
        $this->mergeConfigFrom(
            __DIR__.'/../config/cashier-chip.php',
            'cashier-chip'
        );

        // Register middleware alias
        $this->app['router']->aliasMiddleware('chip.webhook', VerifyWebhookSignature::class);
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (Cashier::$registersRoutes) {
            Route::group([
                'prefix' => config('cashier.path', config('cashier-chip.path', 'chip')),
                'namespace' => 'Aizuddinmanap\CashierChip\Http\Controllers',
                'as' => 'cashier.',
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }
    }

    /**
     * Register the package resources.
     */
    protected function registerResources(): void
    {
        if (Cashier::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cashier.php' => config_path('cashier.php'),
            ], 'cashier-config');

            $this->publishes([
                __DIR__.'/../config/cashier-chip.php' => config_path('cashier-chip.php'),
            ], 'cashier-chip-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cashier-migrations');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cashier-chip-migrations');
        }
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WebhookCommand::class,
            ]);
        }
    }
} 
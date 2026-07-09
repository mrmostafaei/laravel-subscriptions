<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Providers;

use Illuminate\Support\ServiceProvider;
use MiladTech\Subscriptions\Console\Commands\CheckSubscriptionsCommand;
use MiladTech\Subscriptions\Console\Commands\FixUsageCommand;

class SubscriptionsServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'miladtech.subscriptions');

        // Bind eloquent models to IoC container (kept for backward compatibility).
        $this->app->bind('miladtech.subscriptions.plan', fn () => new (config('miladtech.subscriptions.models.plan'))());
        $this->app->bind('miladtech.subscriptions.plan_feature', fn () => new (config('miladtech.subscriptions.models.plan_feature'))());
        $this->app->bind('miladtech.subscriptions.plan_subscription', fn () => new (config('miladtech.subscriptions.models.plan_subscription'))());
        $this->app->bind('miladtech.subscriptions.plan_subscription_usage', fn () => new (config('miladtech.subscriptions.models.plan_subscription_usage'))());
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if (config('miladtech.subscriptions.autoload_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/config.php' => config_path('miladtech.subscriptions.php'),
            ], 'miladtech-subscriptions-config');

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'miladtech-subscriptions-migrations');

            // Only for installs upgrading from v1.x — see UPGRADE.md.
            $this->publishes([
                __DIR__.'/../../database/upgrade' => database_path('migrations'),
            ], 'miladtech-subscriptions-upgrade');

            $this->commands([
                CheckSubscriptionsCommand::class,
                FixUsageCommand::class,
            ]);
        }
    }
}

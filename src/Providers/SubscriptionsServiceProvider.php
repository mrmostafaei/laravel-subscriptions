<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Providers;

use MiladTech\Subscriptions\Models\Plan;
use Illuminate\Support\ServiceProvider;
use MiladTech\Support\Traits\ConsoleTools;
use MiladTech\Subscriptions\Models\PlanFeature;
use MiladTech\Subscriptions\Models\PlanSubscription;
use MiladTech\Subscriptions\Models\PlanSubscriptionUsage;
use MiladTech\Subscriptions\Console\Commands\MigrateCommand;
use MiladTech\Subscriptions\Console\Commands\PublishCommand;
use MiladTech\Subscriptions\Console\Commands\RollbackCommand;

class SubscriptionsServiceProvider extends ServiceProvider
{
    use ConsoleTools;

    /**
     * The commands to be registered.
     *
     * @var array
     */
    protected $commands = [
        MigrateCommand::class => 'command.miladtech.subscriptions.migrate',
        PublishCommand::class => 'command.miladtech.subscriptions.publish',
        RollbackCommand::class => 'command.miladtech.subscriptions.rollback',
    ];

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(realpath(__DIR__.'/../../config/config.php'), 'miladtech.subscriptions');

        // Bind eloquent models to IoC container
        $this->registerModels([
            'miladtech.subscriptions.plan' => Plan::class,
            'miladtech.subscriptions.plan_feature' => PlanFeature::class,
            'miladtech.subscriptions.plan_subscription' => PlanSubscription::class,
            'miladtech.subscriptions.plan_subscription_usage' => PlanSubscriptionUsage::class,
        ]);

        // Register console commands
        $this->registerCommands($this->commands);
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish Resources
        $this->publishesConfig('miladtech/laravel-subscriptions');
        $this->publishesMigrations('miladtech/laravel-subscriptions');
        ! $this->autoloadMigrations('miladtech/laravel-subscriptions') || $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}

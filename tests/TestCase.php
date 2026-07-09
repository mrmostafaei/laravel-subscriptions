<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests;

use Carbon\Carbon;
use MiladTech\Subscriptions\Models\Plan;
use MiladTech\Subscriptions\Providers\SubscriptionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SubscriptionsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.locale', 'en');
        $app['config']->set('app.fallback_locale', 'en');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Ensure the package migrations (registered by the service
        // provider) have run, regardless of the Testbench version.
        $this->artisan('migrate')->run();
    }

    protected function createPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Basic Plan',
            'description' => 'Basic plan description',
            'price' => 100,
            'signup_fee' => 0,
            'currency' => 'USD',
            'invoice_period' => 1,
            'invoice_interval' => 'month',
            'trial_period' => 0,
            'trial_interval' => 'day',
            'grace_period' => 0,
            'grace_interval' => 'day',
        ], $overrides));
    }
}

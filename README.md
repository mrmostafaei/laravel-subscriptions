# MiladTech Subscriptions

**MiladTech Subscriptions** is a flexible plans and subscription management system for Laravel, with the required tools to run your SAAS like services efficiently. It's simple architecture, accompanied by powerful underlying to afford solid platform for your business.

Supports **Laravel 11, 12 and 13** on **PHP 8.2+**, fully self-contained, with lifecycle events, grace periods, suspension, safe concurrent usage tracking, and a complete test suite.

## Considerations

- Payments are out of scope for this package. Listen to the lifecycle events (e.g. `SubscriptionCanceled`) to integrate your payment provider.
- You may extend the core models when you need to override logic behind helper methods like `renew()`, `cancel()`, etc.

## Installation

```shell
composer require miladtech/laravel-subscriptions
```

Migrations load automatically. Optionally publish resources:

```shell
php artisan vendor:publish --tag=miladtech-subscriptions-config
php artisan vendor:publish --tag=miladtech-subscriptions-migrations
php artisan migrate
```

Upgrading from v7? Read [UPGRADE.md](UPGRADE.md).

## Usage

### Add subscriptions to your model

Use the `HasPlanSubscriptions` trait on any subscriber model (usually `User`):

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use MiladTech\Subscriptions\Traits\HasPlanSubscriptions;

class User extends Authenticatable
{
    use HasPlanSubscriptions;
}
```

### Create a plan with features

```php
use MiladTech\Subscriptions\Models\Plan;

$plan = Plan::create([
    'name' => 'Pro',
    'description' => 'Pro plan',
    'price' => 9.99,
    'signup_fee' => 1.99,
    'currency' => 'USD',
    'invoice_period' => 1,
    'invoice_interval' => 'month', // hour|day|week|month|year
    'trial_period' => 15,
    'trial_interval' => 'day',
    'grace_period' => 3,
    'grace_interval' => 'day',
    'active_subscribers_limit' => 1000, // null = unlimited
]);

$plan->features()->saveMany([
    new PlanFeature(['name' => 'SMS', 'slug' => 'sms', 'value' => '100', 'resettable_period' => 1, 'resettable_interval' => 'month']),
    new PlanFeature(['name' => 'API Access', 'slug' => 'api-access', 'value' => 'true']),
    new PlanFeature(['name' => 'Legacy Import', 'slug' => 'legacy-import', 'value' => 'false']),
]);
```

Feature `value` semantics: `"true"` = enabled/unlimited · `"false"`, `"0"`, empty = disabled · numeric = countable limit · anything else = descriptive value (enabled).

### Subscribe

```php
$user->newPlanSubscription('main', $plan);            // starts now
$user->newPlanSubscription('main', $plan, $date);     // starts at a given date
```

Plans without a trial get `trial_ends_at = null`; with a trial, the paid period starts when the trial ends. Inactive plans and plans at their `active_subscribers_limit` throw (`InactivePlanException`, `PlanSubscribersLimitReachedException`).

### Check status

```php
$subscription = $user->planSubscription('main');

$subscription->active();         // on trial, in period, or in grace — and not suspended
$subscription->onTrial();
$subscription->ended();
$subscription->onGracePeriod();  // ended but within the plan's grace window
$subscription->graceEndsAt();    // moment access is truly lost
$subscription->canceled();
$subscription->suspended();
$subscription->remainingDays();

$user->hasActivePlanSubscription();
$user->subscribedTo($planId);
$user->subscribedPlans();
```

### Feature usage — safe by design

All writes run in a transaction with a row lock, so concurrent requests can never exceed a limit.

```php
$subscription->canUseFeature('sms');          // one use
$subscription->canUseFeature('sms', 5);       // five uses at once

$subscription->recordFeatureUsage('sms');     // +1
$subscription->recordFeatureUsage('sms', 3);  // +3
$subscription->setFeatureUsage('sms', 10);    // absolute
$subscription->reduceFeatureUsage('sms', 2);

$subscription->getFeatureUsage('sms');        // used in the current window
$subscription->getFeatureRemainings('sms');   // PHP_INT_MAX for unlimited
$subscription->getFeatureValue('sms');        // raw value
```

`recordFeatureUsage` throws `FeatureNotFoundException` (unknown feature), `FeatureUsageExceededException` (limit reached) or `SubscriptionException` (inactive subscription) — catch them or gate with `canUseFeature()`. All package exceptions extend `MiladTech\Subscriptions\Exceptions\SubscriptionException`.

Resettable features (`resettable_period`/`resettable_interval`) reset automatically when their window elapses, aligned to the subscription start — even when several windows pass without activity.

### Renew

```php
$subscription->renew();    // one invoice period
$subscription->renew(3);   // three periods at once
```

Early renewal **extends** the current period from `ends_at` — subscribers never lose paid time — and keeps usage. Renewal after expiry starts a fresh period from now and clears usage. Renewing also reverts any scheduled cancellation.

### Cancel / uncancel

```php
$subscription->cancel();       // access remains until ends_at
$subscription->cancel(true);   // terminate immediately (ends trial too)
$subscription->uncancel();     // revert a scheduled cancellation
```

### Suspend / resume

```php
$subscription->suspend();        // e.g. failed payment — subscription becomes inactive
$subscription->resume();         // paused time is credited back to ends_at
$subscription->resume(false);    // resume without crediting paused time
```

### Change plan

```php
$subscription->changePlan($newPlan);
```

Usage is migrated to the new plan's features **by slug**: shared features keep their usage (limits may shrink or grow naturally), removed features lose their usage. Different billing frequency starts a new period today. Pass `changePlan($newPlan, syncUsage: false)` to wipe usage instead.

### Events

Every lifecycle change dispatches an event you can listen to:

`SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCanceled`, `SubscriptionUncanceled`, `SubscriptionSuspended`, `SubscriptionResumed`, `SubscriptionPlanChanged`, `SubscriptionExpired`, `SubscriptionTrialEnded`, `FeatureUsageRecorded`, `FeatureUsageReduced` — all under `MiladTech\Subscriptions\Events`.

`SubscriptionExpired` and `SubscriptionTrialEnded` are fired (exactly once per subscription, grace-period aware) by the checker command. Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:check')->everyFifteenMinutes();
```

### Scopes

```php
PlanSubscription::ofSubscriber($user)->get();
PlanSubscription::findActive()->get();
PlanSubscription::findSuspended()->get();
PlanSubscription::findEndingTrial(3)->get();   // trials ending within 3 days
PlanSubscription::findEndedTrial()->get();
PlanSubscription::findEndingPeriod(3)->get();
PlanSubscription::findEndedPeriod()->get();
```

### Models & config

Override table names or swap in your own models via the published config file (`config/miladtech.subscriptions.php`). Slugs are Persian-friendly (via `pishran/laravel-persian-slug`), and `name`/`description` are translatable (via `spatie/laravel-translatable`):

```php
$plan = Plan::create(['name' => ['en' => 'Pro', 'fa' => 'حرفه‌ای'], ...]);
```

Subscription slugs are unique **per subscriber** (every user can own a subscription slugged `main`), and feature slugs are unique **per plan** (so plans can share feature slugs — that's what makes plan changes migrate usage).

## Testing

```shell
composer test
```

CI runs the suite against every supported PHP/Laravel combination.

## License

This software is released under [The MIT License (MIT)](LICENSE).

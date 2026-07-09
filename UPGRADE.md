# Upgrade Guide — v7.x → v8.0

v8 is a major rewrite: self-contained (no more `miladtech/laravel-support`), Laravel 11–13, and many bug fixes. Read the CHANGELOG for the full list.

## 1. Requirements

- PHP >= 8.2 (PHP >= 8.3 for Laravel 13)
- Laravel 11, 12 or 13

## 2. Composer

```bash
composer require miladtech/laravel-subscriptions:^8.0
```

## 3. Database

Existing installs need three new columns and reworked indexes:

```bash
php artisan vendor:publish --tag=miladtech-subscriptions-upgrade
php artisan migrate
```

Fresh installs skip this — the base migrations already include everything.

> Note: the upgrade migration converts the global unique index on subscription slugs to a per-subscriber unique index, and the global unique index on feature slugs to a per-plan unique index. If your data contains duplicates that would now collide (same subscriber + same slug), clean them first.

## 4. Behavior changes to review in your code

**`renew()`** — early renewal now extends from `ends_at` (paid time is preserved, usage kept). Only expired subscriptions restart from "now" and clear usage. Previously every renewal restarted from now and cleared usage.

**`recordFeatureUsage()`** — now throws:
- `FeatureNotFoundException` if the plan doesn't have the feature (previously: fatal error),
- `FeatureUsageExceededException` if the limit would be exceeded (previously: silently overran the limit),
- `SubscriptionException` if the subscription is inactive.

Wrap calls in try/catch or check `canUseFeature()` first.

**`canUseFeature()`** — value semantics are now strict: `"true"` = enabled/unlimited, `"false"`/`"0"`/empty = disabled, numeric = countable limit, anything else = enabled descriptive value.

**`cancel()`** — non-immediate cancel keeps access until `ends_at` (and sets `cancels_at`). Immediate cancel also terminates an active trial.

**Trials** — plans without trial produce `trial_ends_at = null` (previously "now", which momentarily counted as trial).

**Console commands** — `miladtech:migrate:subscriptions` etc. are gone. Use:

```bash
php artisan vendor:publish --tag=miladtech-subscriptions-config
php artisan vendor:publish --tag=miladtech-subscriptions-migrations   # optional
php artisan migrate
```

**Scheduler** — to get `SubscriptionExpired` / `SubscriptionTrialEnded` events, schedule the checker in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:check')->everyFifteenMinutes();
```

**Validation** — the self-validating models (`ValidatingTrait`) are gone. Interval columns are backed by the `Interval` enum (`hour`, `day`, `week`, `month`, `year`); invalid values throw immediately. Validate user input in your own FormRequests, e.g. `Rule::in(Interval::values())`.

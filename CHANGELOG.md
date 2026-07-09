# MiladTech Subscriptions Change Log

All notable changes to this project will be documented in this file.

This project adheres to [Semantic Versioning](CONTRIBUTING.md).

## [v8.0.0] - 2026-07-09

### Added
- Laravel 11, 12 & 13 support (PHP 8.2+).
- `year` billing/reset interval.
- Real grace period support: `active()` / `onGracePeriod()` / `graceEndsAt()` honor the plan's grace settings.
- Suspend/resume: `suspend()`, `resume()` (paused time is credited back to `ends_at`).
- Scheduled cancellation revert: `uncancel()`.
- Multi-period renewal: `renew(int $periods = 1)`.
- Absolute usage setter: `setFeatureUsage()`, plus `canUseFeature($slug, $uses)` for bulk checks.
- Lifecycle events: `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCanceled`, `SubscriptionUncanceled`, `SubscriptionSuspended`, `SubscriptionResumed`, `SubscriptionPlanChanged`, `SubscriptionExpired`, `SubscriptionTrialEnded`, `FeatureUsageRecorded`, `FeatureUsageReduced`.
- `subscriptions:check` artisan command — fires trial-ended/expired events exactly once (grace aware); schedule it.
- `subscriptions:fix-usage` artisan command — repairs v1 usage data: remaps rows orphaned on soft-deleted features or previous plans (matched by slug), merges duplicates; supports `--dry-run` and `--prune`.
- Domain exceptions: `FeatureNotFoundException`, `FeatureUsageExceededException`, `InactivePlanException`, `PlanSubscribersLimitReachedException`, `InvalidIntervalException` (all extend `SubscriptionException`).
- `active_subscribers_limit` is now actually enforced on subscribe.
- Full test suite (PHPUnit + Testbench) and GitHub Actions CI matrix.

### Fixed
- `renew()` no longer discards remaining paid time: early renewals extend from `ends_at`; only expired subscriptions restart from now (and only then is usage cleared).
- `canUseFeature()` boolean logic rewritten (the old condition was always true) with clear value semantics: `"true"` = unlimited, `"false"`/`"0"` = disabled, numeric = limit.
- Plans without trial no longer set a bogus `trial_ends_at` (and no longer crash on `null` trial interval).
- Deleting a plan no longer causes a fatal error (`planSubscriptions()` did not exist); cascades now fire child model events so usage is cleaned up.
- `bootHasSubscriptions()` was misnamed and never ran on `HasPlanSubscriptions`; deleting a subscriber now cleans its subscriptions and usage.
- Race conditions in `recordFeatureUsage()`/`reduceFeatureUsage()` eliminated with transactions + row locks; a DB unique index guarantees one usage row per subscription/feature.
- Usage limits are enforced: exceeding a feature limit throws instead of silently overrunning.
- Expired usage reset now catches up multiple elapsed reset periods (previously `valid_until` could stay in the past).
- Month/year date math no longer overflows (Jan 31 + 1 month = Feb 28, not Mar 3).
- Subscription slugs are unique per subscriber (composite index) instead of globally, so every subscriber can own e.g. `main`; the broken `LIKE %slug%` lookup is now an exact match.
- Feature slugs are unique per plan, letting plans share feature slugs — plan changes migrate usage to the new plan's features by slug and drop usage of removed features.
- `changePlan()` validates the target plan is active; immediate cancel also ends an active trial.
- `subscribedPlans()` return type fixed; `scopeByFeatureSlug` no longer matches features of other plans.

### Changed (breaking)
- Requires PHP >= 8.2 and Laravel 11/12/13; dependency on `miladtech/laravel-support` removed — the package is self-contained.
- Interval columns are cast to the `MiladTech\Subscriptions\Enums\Interval` enum.
- Validation via `ValidatingTrait` removed; invalid intervals throw, DB constraints enforce integrity.
- `recordFeatureUsage()` throws on exceeded limits/unknown features/inactive subscriptions instead of failing silently.
- Legacy `miladtech:migrate/publish/rollback:subscriptions` commands removed — use standard `vendor:publish` and `migrate`.
- Existing installs: publish and run the v2 upgrade migration (`--tag=miladtech-subscriptions-upgrade`), see UPGRADE.md.

## [v7.0.0] - 2024-02-23
- Laravel 10 Support

## [v6.0.1] - 2021-12-15
- Soft deleting children models on soft deleting parent models
- Update the required packages

## [v6.0.0] - 2021-08-22
- Drop PHP v7 support, and upgrade miladtech package dependencies to next major version
- Update composer dependencies
- Merge rules instead of resetting, to allow adequate model override
- Fix constructor initialization order (fill attributes should come next after merging fillables & rules)
- Drop old MySQL versions support that doesn't support json columns
- Upgrade to GitHub-native Dependabot

## [v5.0.3] - 2021-03-15
- Changes in doc to reflect new ofSubscriber breaking change
- Utilize `SoftDeletes` functionality (fix #142)
- Update hardcoded model to use service container IoC
- Add period regardless if it's 0 or more, this should be fine
- Check if there's usage or not (fix #26 & #138)

## [v5.0.2] - 2021-02-19
- Define morphMany parameters explicitly
- Simplify service provider model registration into IoC
- Add startDate optional parameter to new subscription creation (fix #79)
- Fix FeatureSlug confused with FeatureName by mistake (fix #43 #48 #62 #65 #136 #137)
- Breaking Change: Rename "User" to "Subscriber" for more generic naming convention (fix #63)

## [v5.0.1] - 2020-12-25
- Add support for PHP v8

## [v5.0.0] - 2020-12-22
- Upgrade to Laravel v8
- Update validation rules

## [v4.1.0] - 2020-06-15
- Update validation rules
- Drop using miladtech/laravel-cacheable from core packages for more flexibility
  - Caching should be handled on the application layer, not enforced from the core packages
- Drop PHP 7.2 & 7.3 support from travis

## [v4.0.6] - 2020-05-30
- Remove default indent size config
- Add strip_tags validation rule to string fields
- Specify events queue
- Explicitly specify relationship attributes
- Add strip_tags validation rule
- Explicitly define relationship name

## [v4.0.5] - 2020-04-12
- Fix ServiceProvider registerCommands method compatibility

## [v4.0.4] - 2020-04-09
- Tweak artisan command registration
- Reverse commit "Convert database int fields into bigInteger"
- Refactor publish command and allow multiple resource values

## [v4.0.3] - 2020-04-04
- Fix namespace issue

## [v4.0.2] - 2020-04-04
- Enforce consistent artisan command tag namespacing
- Enforce consistent package namespace
- Drop laravel/helpers usage as it's no longer used

## [v4.0.1] - 2020-03-20
- Convert into bigInteger database fields
- Add shortcut -f (force) for artisan publish commands
- Fix migrations path

## [v4.0.0] - 2020-03-15
- Upgrade to Laravel v7.1.x & PHP v7.4.x

## [v3.0.2] - 2020-03-13
- Tweak TravisCI config
- Add migrations autoload option to the package
- Tweak service provider `publishesResources`
- Remove indirect composer dependency
- Drop using global helpers
- Update StyleCI config

## [v3.0.1] - 2019-12-18
- Fix `migrate:reset` args as it doesn't accept --step

## [v3.0.0] - 2019-09-23
- Upgrade to Laravel v6 and update dependencies

## [v2.1.1] - 2019-06-03
- Enforce latest composer package versions

## [v2.1.0] - 2019-06-02
- Update composer deps
- Drop PHP 7.1 travis test
- Refactor migrations and artisan commands, and tweak service provider publishes functionality
- Fix wrong container binding:
  - app('miladtech.subscriptions.plan_features') => app('miladtech.subscriptions.plan_feature')
  - app('miladtech.subscriptions.plan_subscriptions') => app('miladtech.subscriptions.plan_subscription')

## [v2.0.0] - 2019-03-03
- Require PHP 7.2 & Laravel 5.8

## [v1.0.2] - 2018-12-30
- MiladTech\Subscriptions\Services\Period: adding interval received as parameter in constructor to property ->interval

## [v1.0.1] - 2018-12-22
- Update composer dependencies
- Add PHP 7.3 support to travis
- Fix MySQL / PostgreSQL json column compatibility

## [v1.0.0] - 2018-10-01
- Enforce Consistency
- Support Laravel 5.7+
- Rename package to miladtech/laravel-subscriptions

## [v0.0.4] - 2018-09-21
- Update travis php versions
- Define polymorphic relationship parameters explicitly
- Fix fully qualified booking unit methods (fix #20)
- Convert timestamps into datetime fields and add timezone
- Tweak validation rules
- Drop StyleCI multi-language support (paid feature now!)
- Update composer dependencies
- Prepare and tweak testing configuration
- Update StyleCI options
- Update PHPUnit options
- Rename subscription model activation and deactivation methods

## [v0.0.3] - 2018-02-18
- Add PublishCommand to artisan
- Move slug auto generation to the custom HasSlug trait
- Add Rollback Console Command
- Add missing composer dependencies
- Remove useless scopes
- Add PHPUnitPrettyResultPrinter
- Use Carbon global helper
- Update composer dependencies
- Update supplementary files
- Use ->getKey() method instead of ->id
- Typehint method returns
- Drop useless model contracts (models already swappable through IoC)
- Add Laravel v5.6 support
- Simplify IoC binding
- Add force option to artisan commands
- Refactor user_id to a polymorphic relation
- Rename PlanSubscriber trait to HasSubscriptions
- Rename polymorphic relation customer to user
- Rename polymorphic relation customer to user
- Convert interval column data type into string from character

## [v0.0.2] - 2017-09-08
- Fix many issues and apply many enhancements
- Rename package miladtech/laravel-subscriptions from miladtech/subscribable

## v0.0.1 - 2017-06-29
- Tag first release
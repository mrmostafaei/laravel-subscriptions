<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MiladTech\Subscriptions\Enums\Interval;
use MiladTech\Subscriptions\Events\FeatureUsageRecorded;
use MiladTech\Subscriptions\Events\FeatureUsageReduced;
use MiladTech\Subscriptions\Events\SubscriptionCanceled;
use MiladTech\Subscriptions\Events\SubscriptionCreated;
use MiladTech\Subscriptions\Events\SubscriptionPlanChanged;
use MiladTech\Subscriptions\Events\SubscriptionRenewed;
use MiladTech\Subscriptions\Events\SubscriptionResumed;
use MiladTech\Subscriptions\Events\SubscriptionSuspended;
use MiladTech\Subscriptions\Events\SubscriptionUncanceled;
use MiladTech\Subscriptions\Exceptions\FeatureNotFoundException;
use MiladTech\Subscriptions\Exceptions\FeatureUsageExceededException;
use MiladTech\Subscriptions\Exceptions\SubscriptionException;
use MiladTech\Subscriptions\Services\Period;
use MiladTech\Subscriptions\Traits\BelongsToPlan;
use Pishran\LaravelPersianSlug\HasPersianSlug;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

/**
 * MiladTech\Subscriptions\Models\PlanSubscription.
 *
 * @property int                 $id
 * @property int                 $subscriber_id
 * @property string              $subscriber_type
 * @property int                 $plan_id
 * @property string              $slug
 * @property array               $name
 * @property array|null         $description
 * @property string|null        $timezone
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $ends_at
 * @property \Carbon\Carbon|null $cancels_at
 * @property \Carbon\Carbon|null $canceled_at
 * @property \Carbon\Carbon|null $suspended_at
 * @property \Carbon\Carbon|null $expired_notified_at
 * @property \Carbon\Carbon|null $trial_ended_notified_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read \MiladTech\Subscriptions\Models\Plan                                                             $plan
 * @property-read \Illuminate\Database\Eloquent\Collection|\MiladTech\Subscriptions\Models\PlanSubscriptionUsage[] $usage
 * @property-read \Illuminate\Database\Eloquent\Model                                                              $subscriber
 */
class PlanSubscription extends Model
{
    use BelongsToPlan;
    use HasPersianSlug;
    use HasTranslations;
    use SoftDeletes;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'subscriber_id',
        'subscriber_type',
        'plan_id',
        'slug',
        'name',
        'description',
        'timezone',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'cancels_at',
        'canceled_at',
    ];

    /**
     * {@inheritdoc}
     */
    protected $casts = [
        'subscriber_id' => 'integer',
        'subscriber_type' => 'string',
        'plan_id' => 'integer',
        'slug' => 'string',
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancels_at' => 'datetime',
        'canceled_at' => 'datetime',
        'suspended_at' => 'datetime',
        'expired_notified_at' => 'datetime',
        'trial_ended_notified_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * {@inheritdoc}
     */
    protected $dispatchesEvents = [
        'created' => SubscriptionCreated::class,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    public $translatable = [
        'name',
        'description',
    ];

    /**
     * Create a new Eloquent model instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('miladtech.subscriptions.tables.plan_subscriptions'));

        parent::__construct($attributes);
    }

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $subscription): void {
            if (! $subscription->starts_at || ! $subscription->ends_at) {
                $subscription->setNewPeriod();
            }

            $subscription->makeSlugUniqueForSubscriber();
        });

        static::deleted(function (self $subscription): void {
            $subscription->usage()->delete();
        });
    }

    /**
     * Get the options for generating the slug.
     *
     * Duplicate slugs are allowed globally; uniqueness is guaranteed
     * per subscriber (see makeSlugUniqueForSubscriber and the composite
     * unique index) so every subscriber can own a subscription called
     * e.g. "main" and look it up reliably by that exact slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->doNotGenerateSlugsOnUpdate()
            ->generateSlugsFrom('name')
            ->allowDuplicateSlugs()
            ->saveSlugsTo('slug');
    }

    /**
     * Get the owning subscriber.
     */
    public function subscriber(): MorphTo
    {
        return $this->morphTo('subscriber', 'subscriber_type', 'subscriber_id', 'id');
    }

    /**
     * The subscription may have many usage.
     */
    public function usage(): HasMany
    {
        return $this->hasMany(config('miladtech.subscriptions.models.plan_subscription_usage'), 'subscription_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | State checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if subscription is active: not suspended, and either on trial,
     * within the current period, or within the plan's grace period.
     */
    public function active(): bool
    {
        if ($this->suspended()) {
            return false;
        }

        return $this->onTrial() || ! $this->ended() || $this->onGracePeriod();
    }

    /**
     * Check if subscription is inactive.
     */
    public function inactive(): bool
    {
        return ! $this->active();
    }

    /**
     * Check if subscription is currently on trial.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Check if subscription is canceled (scheduled or immediate).
     */
    public function canceled(): bool
    {
        return $this->canceled_at !== null;
    }

    /**
     * Check if subscription period has ended.
     */
    public function ended(): bool
    {
        return $this->ends_at !== null && Carbon::now()->gte($this->ends_at);
    }

    /**
     * Check if the ended subscription is still within its plan's grace period.
     */
    public function onGracePeriod(): bool
    {
        if (! $this->ended() || ! $this->plan?->hasGrace()) {
            return false;
        }

        $graceEndsAt = $this->graceEndsAt();

        return $graceEndsAt !== null && Carbon::now()->lt($graceEndsAt);
    }

    /**
     * The moment access is truly lost: end of period plus grace (if any).
     */
    public function graceEndsAt(): ?CarbonInterface
    {
        if ($this->ends_at === null) {
            return null;
        }

        if (! $this->plan?->hasGrace()) {
            return $this->ends_at->copy();
        }

        return $this->plan->grace_interval->addTo($this->ends_at->copy(), $this->plan->grace_period);
    }

    /**
     * Check if subscription is suspended.
     */
    public function suspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Remaining full days of the current period (0 when ended).
     */
    public function remainingDays(): int
    {
        if ($this->ends_at === null || ! $this->ends_at->isFuture()) {
            return 0;
        }

        return (int) Carbon::now()->diffInDays($this->ends_at, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle actions
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel subscription.
     *
     * By default the subscription stays usable until the end of the paid
     * period (`cancels_at` = `ends_at`). Pass `$immediately = true` to
     * terminate access right away (also ends an active trial).
     *
     * @return $this
     */
    public function cancel(bool $immediately = false): static
    {
        $this->canceled_at = Carbon::now();
        $this->cancels_at = $this->ends_at?->copy();

        if ($immediately) {
            $this->cancels_at = $this->canceled_at->copy();
            $this->ends_at = $this->canceled_at->copy();

            if ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture()) {
                $this->trial_ends_at = $this->canceled_at->copy();
            }
        }

        $this->save();

        event(new SubscriptionCanceled($this));

        return $this;
    }

    /**
     * Revert a scheduled cancellation (only possible while the period is running).
     *
     * @throws \MiladTech\Subscriptions\Exceptions\SubscriptionException
     *
     * @return $this
     */
    public function uncancel(): static
    {
        if (! $this->canceled()) {
            return $this;
        }

        if ($this->ended()) {
            throw new SubscriptionException('Cannot uncancel an ended subscription; use renew() instead.');
        }

        $this->canceled_at = null;
        $this->cancels_at = null;
        $this->save();

        event(new SubscriptionUncanceled($this));

        return $this;
    }

    /**
     * Suspend the subscription (e.g. failed payment, abuse).
     *
     * @return $this
     */
    public function suspend(): static
    {
        if ($this->suspended()) {
            return $this;
        }

        $this->suspended_at = Carbon::now();
        $this->save();

        event(new SubscriptionSuspended($this));

        return $this;
    }

    /**
     * Resume a suspended subscription.
     *
     * When `$creditPausedTime` is true (default), the time spent suspended
     * is added back to `ends_at`, so subscribers never lose paid time.
     *
     * @return $this
     */
    public function resume(bool $creditPausedTime = true): static
    {
        if (! $this->suspended()) {
            return $this;
        }

        if ($creditPausedTime && $this->ends_at !== null && $this->ends_at->gt($this->suspended_at)) {
            $pausedSeconds = (int) $this->suspended_at->diffInSeconds(Carbon::now(), true);
            $this->ends_at = $this->ends_at->copy()->addSeconds($pausedSeconds);
        }

        $this->suspended_at = null;
        $this->save();

        event(new SubscriptionResumed($this));

        return $this;
    }

    /**
     * Renew the subscription for N invoice periods.
     *
     * - If the subscription already ended: a fresh period starts now and
     *   usage data is cleared.
     * - If it is still running (early renewal): the new period is appended
     *   to `ends_at`, so the subscriber keeps every day already paid for,
     *   and current usage data is preserved.
     *
     * Renewing also clears any cancellation, reactivating the subscription.
     *
     * @throws \MiladTech\Subscriptions\Exceptions\SubscriptionException
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function renew(int $periods = 1): static
    {
        if ($periods < 1) {
            throw new InvalidArgumentException("Renewal periods must be at least 1, [{$periods}] given.");
        }

        if ($this->suspended()) {
            throw new SubscriptionException('Cannot renew a suspended subscription; resume it first.');
        }

        $plan = $this->plan;

        if ($plan === null) {
            throw new SubscriptionException('Cannot renew a subscription whose plan no longer exists.');
        }

        return DB::transaction(function () use ($periods, $plan): static {
            if ($this->ended()) {
                // Expired: start a fresh billing cycle from now.
                $this->usage()->delete();

                $period = new Period($plan->invoice_interval, $plan->invoice_period * $periods, Carbon::now());
                $this->starts_at = $period->getStartDate();
                $this->ends_at = $period->getEndDate();
            } else {
                // Early renewal: extend the current cycle, never losing paid time.
                $period = new Period($plan->invoice_interval, $plan->invoice_period * $periods, $this->ends_at);
                $this->ends_at = $period->getEndDate();
            }

            $this->canceled_at = null;
            $this->cancels_at = null;
            $this->expired_notified_at = null;
            $this->save();

            event(new SubscriptionRenewed($this));

            return $this;
        });
    }

    /**
     * Change subscription plan.
     *
     * Usage records are migrated to the new plan's features (matched by
     * feature slug): usage of features that also exist on the new plan is
     * kept — so raising or lowering feature limits works naturally — and
     * usage of features missing from the new plan is removed. If the two
     * plans bill on different frequencies, a new period starts today.
     *
     * Note: `is_active` is NOT enforced here — changing an existing
     * subscriber's plan is an internal/admin operation, and inactive
     * plans are commonly used as private/custom plans that admins
     * attach manually. `is_active` only gates NEW public subscriptions
     * (see HasPlanSubscriptions::newPlanSubscription).
     *
     * @return $this
     */
    public function changePlan(Plan $newPlan, bool $syncUsage = true): static
    {
        if ($newPlan->getKey() === $this->plan_id) {
            return $this;
        }

        return DB::transaction(function () use ($newPlan, $syncUsage): static {
            $oldPlan = $this->plan;

            $sameBillingFrequency = $oldPlan !== null
                && $oldPlan->invoice_interval === $newPlan->invoice_interval
                && $oldPlan->invoice_period === $newPlan->invoice_period;

            if (! $sameBillingFrequency) {
                $this->setNewPeriod($newPlan->invoice_interval, $newPlan->invoice_period);
            }

            if ($syncUsage) {
                $newFeatures = $newPlan->features()->get()->keyBy('slug');

                $this->usage()->with('feature')->get()->each(function (PlanSubscriptionUsage $usage) use ($newFeatures): void {
                    $slug = $usage->feature?->slug;
                    $target = $slug !== null ? ($newFeatures[$slug] ?? null) : null;

                    if ($target === null) {
                        $usage->delete();

                        return;
                    }

                    $usage->feature_id = $target->getKey();

                    if (! $target->isResettable()) {
                        $usage->valid_until = null;
                    }

                    $usage->save();
                });
            } else {
                $this->usage()->delete();
            }

            $this->plan_id = $newPlan->getKey();
            $this->save();
            $this->unsetRelation('plan');

            event(new SubscriptionPlanChanged($this, $oldPlan, $newPlan));

            return $this;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Feature usage
    |--------------------------------------------------------------------------
    */

    /**
     * Record feature usage, safely and atomically.
     *
     * Runs inside a transaction with a row lock, so concurrent requests can
     * never push usage beyond the feature limit.
     *
     * @throws \MiladTech\Subscriptions\Exceptions\SubscriptionException          when the subscription is inactive
     * @throws \MiladTech\Subscriptions\Exceptions\FeatureNotFoundException       when the plan has no such feature
     * @throws \MiladTech\Subscriptions\Exceptions\FeatureUsageExceededException  when the limit would be exceeded
     * @throws \InvalidArgumentException
     */
    public function recordFeatureUsage(string $featureSlug, int $uses = 1, bool $incremental = true): PlanSubscriptionUsage
    {
        if ($uses < 0) {
            throw new InvalidArgumentException("Uses must be zero or greater, [{$uses}] given.");
        }

        if (! $this->active()) {
            throw new SubscriptionException('Cannot record feature usage on an inactive subscription.');
        }

        $feature = $this->plan->getFeatureBySlug($featureSlug);

        if ($feature === null) {
            throw new FeatureNotFoundException($featureSlug, (string) $this->plan->slug);
        }

        if ($feature->isDisabled()) {
            throw new FeatureUsageExceededException($featureSlug, $uses, 0);
        }

        return DB::transaction(function () use ($feature, $featureSlug, $uses, $incremental): PlanSubscriptionUsage {
            /** @var \MiladTech\Subscriptions\Models\PlanSubscriptionUsage $usage */
            $usage = $this->usage()
                ->where('feature_id', $feature->getKey())
                ->lockForUpdate()
                ->first() ?? $this->usage()->make(['feature_id' => $feature->getKey(), 'used' => 0]);

            $this->resetUsageIfExpired($usage, $feature);

            $newUsed = $incremental ? $usage->used + $uses : $uses;

            $limit = $feature->limit();

            if ($limit !== null && $newUsed > $limit) {
                throw new FeatureUsageExceededException($featureSlug, $uses, max(0, $limit - $usage->used));
            }

            $usage->used = $newUsed;
            $usage->save();

            event(new FeatureUsageRecorded($usage));

            return $usage;
        });
    }

    /**
     * Reduce feature usage (never below zero).
     */
    public function reduceFeatureUsage(string $featureSlug, int $uses = 1): ?PlanSubscriptionUsage
    {
        if ($uses < 0) {
            throw new InvalidArgumentException("Uses must be zero or greater, [{$uses}] given.");
        }

        return DB::transaction(function () use ($featureSlug, $uses): ?PlanSubscriptionUsage {
            /** @var \MiladTech\Subscriptions\Models\PlanSubscriptionUsage|null $usage */
            $usage = $this->usage()->byFeatureSlug($featureSlug)->lockForUpdate()->first();

            if ($usage === null) {
                return null;
            }

            $usage->used = max($usage->used - $uses, 0);
            $usage->save();

            event(new FeatureUsageReduced($usage));

            return $usage;
        });
    }

    /**
     * Set feature usage to an absolute value.
     */
    public function setFeatureUsage(string $featureSlug, int $uses): PlanSubscriptionUsage
    {
        return $this->recordFeatureUsage($featureSlug, $uses, false);
    }

    /**
     * Determine if the feature can be used (a given number of times).
     */
    public function canUseFeature(string $featureSlug, int $uses = 1): bool
    {
        if (! $this->active()) {
            return false;
        }

        $feature = $this->plan->getFeatureBySlug($featureSlug);

        if ($feature === null || $feature->isDisabled()) {
            return false;
        }

        if ($feature->limit() === null) {
            // Boolean ("true") or descriptive feature: enabled, not countable.
            return true;
        }

        return $this->getFeatureRemainings($featureSlug) >= max(1, $uses);
    }

    /**
     * Get how many times the feature has been used in the current usage period.
     */
    public function getFeatureUsage(string $featureSlug): int
    {
        /** @var \MiladTech\Subscriptions\Models\PlanSubscriptionUsage|null $usage */
        $usage = $this->usage()->byFeatureSlug($featureSlug)->first();

        return ($usage === null || $usage->expired()) ? 0 : $usage->used;
    }

    /**
     * Get the remaining uses of a feature.
     *
     * Unlimited features return PHP_INT_MAX; disabled or missing features return 0.
     */
    public function getFeatureRemainings(string $featureSlug): int
    {
        $feature = $this->plan->getFeatureBySlug($featureSlug);

        if ($feature === null || $feature->isDisabled()) {
            return 0;
        }

        $limit = $feature->limit();

        if ($limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->getFeatureUsage($featureSlug));
    }

    /**
     * Get feature raw value.
     */
    public function getFeatureValue(string $featureSlug): ?string
    {
        return $this->plan->getFeatureBySlug($featureSlug)?->value;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOfSubscriber(Builder $builder, Model $subscriber): Builder
    {
        return $builder->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey());
    }

    public function scopeFindEndingTrial(Builder $builder, int $dayRange = 3): Builder
    {
        return $builder->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addDays($dayRange)]);
    }

    public function scopeFindEndedTrial(Builder $builder): Builder
    {
        return $builder->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', Carbon::now());
    }

    public function scopeFindEndingPeriod(Builder $builder, int $dayRange = 3): Builder
    {
        return $builder->whereBetween('ends_at', [Carbon::now(), Carbon::now()->addDays($dayRange)]);
    }

    public function scopeFindEndedPeriod(Builder $builder): Builder
    {
        return $builder->whereNotNull('ends_at')->where('ends_at', '<=', Carbon::now());
    }

    public function scopeFindActive(Builder $builder): Builder
    {
        return $builder->whereNull('suspended_at')
            ->where(function (Builder $query): void {
                $query->where('ends_at', '>', Carbon::now())
                    ->orWhere('trial_ends_at', '>', Carbon::now());
            });
    }

    public function scopeFindSuspended(Builder $builder): Builder
    {
        return $builder->whereNotNull('suspended_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Set a new subscription period on the model (not persisted).
     *
     * @return $this
     */
    protected function setNewPeriod(Interval|string|null $invoiceInterval = null, ?int $invoicePeriod = null, CarbonInterface|string|null $start = null): static
    {
        $plan = $this->relationLoaded('plan') ? $this->plan : $this->plan()->first();

        $interval = $invoiceInterval ?? $plan?->invoice_interval ?? Interval::MONTH;
        $count = $invoicePeriod ?? $plan?->invoice_period ?? 1;

        $period = new Period($interval, $count, $start);

        $this->starts_at = $period->getStartDate();
        $this->ends_at = $period->getEndDate();

        return $this;
    }

    /**
     * Reset an expired usage record, catching up as many reset periods as
     * have elapsed so `valid_until` always lands in the future.
     */
    protected function resetUsageIfExpired(PlanSubscriptionUsage $usage, PlanFeature $feature): void
    {
        if (! $feature->isResettable()) {
            return;
        }

        if ($usage->valid_until === null) {
            // Anchor reset cycles to the subscription start so they
            // stay aligned with the billing period.
            $validUntil = $feature->getResetDate($this->starts_at ?? $this->created_at ?? Carbon::now());

            while ($validUntil->isPast()) {
                $validUntil = $feature->getResetDate($validUntil);
            }

            $usage->valid_until = $validUntil;

            return;
        }

        if ($usage->expired()) {
            $validUntil = $usage->valid_until->copy();

            while ($validUntil->isPast()) {
                $validUntil = $feature->getResetDate($validUntil);
            }

            $usage->valid_until = $validUntil;
            $usage->used = 0;
        }
    }

    /**
     * Guarantee the generated slug is unique per subscriber by suffixing.
     */
    protected function makeSlugUniqueForSubscriber(): void
    {
        $base = $this->slug;

        if (! $base || ! $this->subscriber_type || ! $this->subscriber_id) {
            return;
        }

        $slug = $base;
        $suffix = 1;

        while (static::withTrashed()
            ->where('subscriber_type', $this->subscriber_type)
            ->where('subscriber_id', $this->subscriber_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        $this->slug = $slug;
    }
}

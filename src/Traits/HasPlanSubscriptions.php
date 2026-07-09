<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Traits;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use MiladTech\Subscriptions\Exceptions\InactivePlanException;
use MiladTech\Subscriptions\Exceptions\PlanSubscribersLimitReachedException;
use MiladTech\Subscriptions\Models\Plan;
use MiladTech\Subscriptions\Models\PlanSubscription;
use MiladTech\Subscriptions\Services\Period;

trait HasPlanSubscriptions
{
    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @param string $related
     * @param string $name
     * @param string $type
     * @param string $id
     * @param string $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    abstract public function morphMany($related, $name, $type = null, $id = null, $localKey = null);

    /**
     * Boot the HasPlanSubscriptions trait for the model.
     *
     * Note: the method name MUST match the trait name for Laravel
     * to call it (this was broken before and never ran).
     */
    protected static function bootHasPlanSubscriptions(): void
    {
        static::deleted(function ($subscriber): void {
            // Iterate models (instead of a bulk query delete) so each
            // subscription fires its own deleted hooks and cleans usage.
            $subscriber->planSubscriptions()->get()->each->delete();
        });
    }

    /**
     * The subscriber may have many plan subscriptions.
     */
    public function planSubscriptions(): MorphMany
    {
        return $this->morphMany(config('miladtech.subscriptions.models.plan_subscription'), 'subscriber', 'subscriber_type', 'subscriber_id');
    }

    /**
     * A model may have many active plan subscriptions.
     */
    public function activePlanSubscriptions(): Collection
    {
        return $this->planSubscriptions->reject->inactive();
    }

    /**
     * Get the first active plan subscription, if any.
     */
    public function firstActivePlanSubscription(): ?PlanSubscription
    {
        return $this->activePlanSubscriptions()->first();
    }

    /**
     * Check if the subscriber has any active subscription.
     */
    public function hasActivePlanSubscription(): bool
    {
        return $this->firstActivePlanSubscription() !== null;
    }

    /**
     * Get a plan subscription by exact slug.
     */
    public function planSubscription(string $subscriptionSlug): ?PlanSubscription
    {
        return $this->planSubscriptions()->where('slug', $subscriptionSlug)->first();
    }

    /**
     * Get subscribed plans.
     */
    public function subscribedPlans(): Collection
    {
        $planIds = $this->planSubscriptions->reject->inactive()->pluck('plan_id')->unique();

        $planModel = config('miladtech.subscriptions.models.plan');

        return $planModel::whereIn('id', $planIds)->get();
    }

    /**
     * Check if the subscriber has an active subscription to the given plan.
     */
    public function subscribedTo(int|string $planId): bool
    {
        return $this->planSubscriptions()
            ->where('plan_id', $planId)
            ->get()
            ->contains(fn (PlanSubscription $subscription): bool => $subscription->active());
    }

    /**
     * Subscribe subscriber to a new plan.
     *
     * Fixes over the legacy implementation:
     *  - plans without a trial no longer crash and get `trial_ends_at = null`
     *  - inactive plans and plans at their subscriber limit are rejected
     *  - the paid period starts at trial end only when a trial exists
     *
     * @throws \MiladTech\Subscriptions\Exceptions\InactivePlanException
     * @throws \MiladTech\Subscriptions\Exceptions\PlanSubscribersLimitReachedException
     */
    public function newPlanSubscription(string $name, Plan $plan, CarbonInterface|string|null $startDate = null): PlanSubscription
    {
        if (! $plan->is_active) {
            throw new InactivePlanException((string) $plan->slug);
        }

        if (! $plan->hasSubscriberCapacity()) {
            throw new PlanSubscribersLimitReachedException((string) $plan->slug, (int) $plan->active_subscribers_limit);
        }

        $start = match (true) {
            $startDate === null => Carbon::now(),
            $startDate instanceof CarbonInterface => Carbon::instance($startDate),
            default => Carbon::parse($startDate),
        };

        if ($plan->hasTrial()) {
            $trial = new Period($plan->trial_interval, $plan->trial_period, $start);
            $trialEndsAt = $trial->getEndDate();
            $period = new Period($plan->invoice_interval, $plan->invoice_period, $trialEndsAt);
        } else {
            $trialEndsAt = null;
            $period = new Period($plan->invoice_interval, $plan->invoice_period, $start);
        }

        return $this->planSubscriptions()->create([
            'name' => $name,
            'plan_id' => $plan->getKey(),
            'trial_ends_at' => $trialEndsAt,
            'starts_at' => $period->getStartDate(),
            'ends_at' => $period->getEndDate(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use MiladTech\Subscriptions\Models\Plan;
use MiladTech\Subscriptions\Models\PlanSubscription;

/**
 * Thin alias around HasPlanSubscriptions kept for backward compatibility.
 * Both traits share one implementation, so behavior can never drift apart.
 * Use HasPlanSubscriptions (or this alias) — never both on the same model.
 */
trait HasSubscriptions
{
    use HasPlanSubscriptions;

    /**
     * The subscriber may have many subscriptions.
     */
    public function subscriptions(): MorphMany
    {
        return $this->planSubscriptions();
    }

    /**
     * A model may have many active subscriptions.
     */
    public function activeSubscriptions(): Collection
    {
        return $this->activePlanSubscriptions();
    }

    /**
     * Get a subscription by exact slug.
     */
    public function subscription(string $subscriptionSlug): ?PlanSubscription
    {
        return $this->planSubscription($subscriptionSlug);
    }

    /**
     * Subscribe subscriber to a new plan.
     */
    public function newSubscription(string $name, Plan $plan, CarbonInterface|string|null $startDate = null): PlanSubscription
    {
        return $this->newPlanSubscription($name, $plan, $startDate);
    }
}

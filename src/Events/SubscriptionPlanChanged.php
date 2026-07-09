<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Events;

use MiladTech\Subscriptions\Models\Plan;
use MiladTech\Subscriptions\Models\PlanSubscription;

class SubscriptionPlanChanged extends SubscriptionEvent
{
    public function __construct(
        PlanSubscription $subscription,
        public ?Plan $oldPlan,
        public Plan $newPlan,
    ) {
        parent::__construct($subscription);
    }
}

<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MiladTech\Subscriptions\Models\PlanSubscriptionUsage;

class FeatureUsageReduced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public PlanSubscriptionUsage $usage)
    {
    }
}

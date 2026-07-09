<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

class PlanSubscribersLimitReachedException extends SubscriptionException
{
    public function __construct(string $planName, int $limit)
    {
        parent::__construct(sprintf(
            'Plan [%s] has reached its active subscribers limit of %d.',
            $planName,
            $limit
        ));
    }
}

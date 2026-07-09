<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

use MiladTech\Subscriptions\Enums\Interval;

class InvalidIntervalException extends SubscriptionException
{
    public function __construct(string $interval)
    {
        parent::__construct(sprintf(
            'Invalid interval [%s]. Allowed intervals: %s.',
            $interval,
            implode(', ', Interval::values())
        ));
    }
}

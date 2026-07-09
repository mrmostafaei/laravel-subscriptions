<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

class InactivePlanException extends SubscriptionException
{
    public function __construct(string $planName)
    {
        parent::__construct(sprintf('Cannot subscribe to inactive plan [%s].', $planName));
    }
}

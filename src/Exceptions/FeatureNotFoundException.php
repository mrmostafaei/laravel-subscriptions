<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

class FeatureNotFoundException extends SubscriptionException
{
    public function __construct(string $featureSlug, string $planName = '')
    {
        parent::__construct(sprintf(
            'Feature [%s] does not exist on plan%s.',
            $featureSlug,
            $planName !== '' ? " [{$planName}]" : ''
        ));
    }
}

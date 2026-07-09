<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

class FeatureUsageExceededException extends SubscriptionException
{
    public function __construct(
        public readonly string $featureSlug,
        public readonly int $requested,
        public readonly int $remaining,
    ) {
        parent::__construct(sprintf(
            'Usage of feature [%s] denied: requested %d but only %d remaining.',
            $featureSlug,
            $requested,
            $remaining
        ));
    }
}

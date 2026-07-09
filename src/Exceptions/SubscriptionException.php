<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Exceptions;

use RuntimeException;

/**
 * Base exception for every domain error thrown by this package,
 * so consumers can `catch (SubscriptionException $e)` in one place.
 */
class SubscriptionException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Enums;

use Carbon\CarbonInterface;
use MiladTech\Subscriptions\Exceptions\InvalidIntervalException;

enum Interval: string
{
    case HOUR = 'hour';
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';

    /**
     * Resolve an interval from a string, throwing a domain exception on failure.
     */
    public static function fromString(self|string $interval): self
    {
        if ($interval instanceof self) {
            return $interval;
        }

        return self::tryFrom(strtolower(trim($interval)))
            ?? throw new InvalidIntervalException($interval);
    }

    /**
     * Add this interval N times to the given date (immutable-safe, no month overflow).
     * Works with both Carbon and CarbonImmutable instances.
     */
    public function addTo(CarbonInterface $date, int $count): CarbonInterface
    {
        $date = $date->copy();

        return match ($this) {
            self::HOUR => $date->addHours($count),
            self::DAY => $date->addDays($count),
            self::WEEK => $date->addWeeks($count),
            self::MONTH => $date->addMonthsNoOverflow($count),
            self::YEAR => $date->addYearsNoOverflow($count),
        };
    }

    /**
     * All valid interval values (useful for validation rules).
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

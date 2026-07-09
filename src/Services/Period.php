<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use MiladTech\Subscriptions\Enums\Interval;

class Period
{
    protected Carbon $start;

    protected Carbon $end;

    protected Interval $interval;

    protected int $period;

    /**
     * Create a new Period instance.
     *
     * @param \MiladTech\Subscriptions\Enums\Interval|string $interval hour|day|week|month|year
     * @param int                                            $count    how many intervals the period spans (>= 0)
     * @param \Carbon\CarbonInterface|string|null            $start    period start (defaults to now)
     *
     * @throws \MiladTech\Subscriptions\Exceptions\InvalidIntervalException
     * @throws \InvalidArgumentException
     */
    public function __construct(Interval|string $interval = Interval::MONTH, int $count = 1, CarbonInterface|string|null $start = null)
    {
        if ($count < 0) {
            throw new InvalidArgumentException("Period count must be zero or greater, [{$count}] given.");
        }

        $this->interval = Interval::fromString($interval);
        $this->period = $count;

        $this->start = match (true) {
            $start === null, $start === '' => Carbon::now(),
            $start instanceof CarbonInterface => Carbon::instance($start),
            default => Carbon::parse($start),
        };

        // Month/year additions never overflow (e.g. Jan 31 + 1 month = Feb 28, not Mar 3).
        $this->end = Carbon::instance($this->interval->addTo($this->start, $this->period));
    }

    public function getStartDate(): Carbon
    {
        return $this->start->copy();
    }

    public function getEndDate(): Carbon
    {
        return $this->end->copy();
    }

    public function getInterval(): Interval
    {
        return $this->interval;
    }

    public function getIntervalCount(): int
    {
        return $this->period;
    }
}

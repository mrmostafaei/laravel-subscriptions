<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests\Unit;

use Carbon\Carbon;
use InvalidArgumentException;
use MiladTech\Subscriptions\Enums\Interval;
use MiladTech\Subscriptions\Exceptions\InvalidIntervalException;
use MiladTech\Subscriptions\Services\Period;
use MiladTech\Subscriptions\Tests\TestCase;

class PeriodTest extends TestCase
{
    public function test_it_defaults_to_one_month_starting_now(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');

        $period = new Period();

        $this->assertTrue($period->getStartDate()->eq('2026-03-10 12:00:00'));
        $this->assertTrue($period->getEndDate()->eq('2026-04-10 12:00:00'));
        $this->assertSame(Interval::MONTH, $period->getInterval());
        $this->assertSame(1, $period->getIntervalCount());
    }

    public function test_month_addition_does_not_overflow(): void
    {
        // Jan 31 + 1 month must be Feb 28, never Mar 3.
        $period = new Period('month', 1, '2026-01-31 00:00:00');

        $this->assertSame('2026-02-28', $period->getEndDate()->toDateString());
    }

    public function test_all_intervals_are_supported(): void
    {
        $start = '2026-01-01 00:00:00';

        $this->assertSame('2026-01-01 06:00:00', (new Period('hour', 6, $start))->getEndDate()->toDateTimeString());
        $this->assertSame('2026-01-11 00:00:00', (new Period('day', 10, $start))->getEndDate()->toDateTimeString());
        $this->assertSame('2026-01-15 00:00:00', (new Period('week', 2, $start))->getEndDate()->toDateTimeString());
        $this->assertSame('2026-04-01 00:00:00', (new Period('month', 3, $start))->getEndDate()->toDateTimeString());
        $this->assertSame('2027-01-01 00:00:00', (new Period('year', 1, $start))->getEndDate()->toDateTimeString());
    }

    public function test_invalid_interval_throws(): void
    {
        $this->expectException(InvalidIntervalException::class);

        new Period('decade', 1);
    }

    public function test_negative_count_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Period('month', -1);
    }

    public function test_it_never_mutates_the_given_start_date(): void
    {
        $start = Carbon::parse('2026-01-01 00:00:00');

        new Period('month', 6, $start);

        $this->assertSame('2026-01-01 00:00:00', $start->toDateTimeString());
    }

    public function test_zero_count_yields_empty_period(): void
    {
        $period = new Period('day', 0, '2026-01-01 00:00:00');

        $this->assertTrue($period->getStartDate()->eq($period->getEndDate()));
    }
}

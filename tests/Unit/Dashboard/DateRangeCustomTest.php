<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Dashboard;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The free dates of a period: strict reading, and a previous period that stays contiguous.
 */
final class DateRangeCustomTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-11-09 15:30:00');
    }

    public function testTwoDaysBecomeTheWholeDaysBetweenThem(): void
    {
        $range = DateRange::custom('2026-10-01', '2026-10-15', $this->today);

        self::assertNotNull($range);
        self::assertSame('2026-10-01 00:00:00', $range->fromSql());
        self::assertSame('2026-10-15 23:59:59', $range->toSql());
        self::assertSame(DateRange::PRESET_CUSTOM, $range->preset);
        self::assertSame(15, $range->days());
    }

    public function testTodayIsAllowed(): void
    {
        self::assertNotNull(DateRange::custom('2026-11-09', '2026-11-09', $this->today));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedPeriods(): iterable
    {
        yield 'not a date' => ['2026-13-01', '2026-13-05'];
        yield 'day that does not exist' => ['2026-02-30', '2026-03-05'];
        yield 'wrong format' => ['01/10/2026', '15/10/2026'];
        yield 'time given' => ['2026-10-01 10:00', '2026-10-15'];
        yield 'empty' => ['', ''];
        yield 'inverted' => ['2026-10-15', '2026-10-01'];
        yield 'in the future' => ['2026-11-01', '2026-11-10'];
        yield 'more than three years' => ['2023-11-09', '2026-11-09'];
    }

    #[DataProvider('refusedPeriods')]
    public function testAnInvalidPeriodIsRefused(string $from, string $to): void
    {
        self::assertNull(DateRange::custom($from, $to, $this->today));
    }

    public function testThreeYearsMinusOneDayIsTheLongestPeriod(): void
    {
        self::assertNotNull(DateRange::custom('2023-11-10', '2026-11-09', $this->today), '1096 days');
        self::assertNull(DateRange::custom('2023-11-09', '2026-11-09', $this->today), '1097 days');
    }

    public function testThePeriodsOfTheFirstHalfAndOfTheEndOfAMonthMakeTheMonth(): void
    {
        $first = DateRange::custom('2026-10-01', '2026-10-15', $this->today);
        $second = DateRange::custom('2026-10-16', '2026-10-31', $this->today);
        $month = DateRange::custom('2026-10-01', '2026-10-31', $this->today);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($month);
        self::assertSame(1, $second->from->getTimestamp() - $first->to->getTimestamp(), 'No second lost, none counted twice.');
        self::assertEquals($month->from, $first->from);
        self::assertEquals($month->to, $second->to);
    }

    public function testThePreviousOfAFreePeriodIsTheSameNumberOfDaysRightBefore(): void
    {
        $range = DateRange::custom('2026-04-01', '2026-04-30', $this->today);
        self::assertNotNull($range);

        $previous = $range->previous();

        self::assertSame('2026-03-02 00:00:00', $previous->fromSql());
        self::assertSame('2026-03-31 23:59:59', $previous->toSql());
        self::assertSame(30, $previous->days());
        self::assertSame(DateRange::PRESET_CUSTOM, $previous->preset);
    }

    public function testTheRequestReadsTheFreeDatesWhenTheyAreValid(): void
    {
        $range = DateRange::fromRequest(new Request(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-15']), $this->today);

        self::assertSame(DateRange::PRESET_CUSTOM, $range->preset);
        self::assertSame('2026-10-01 00:00:00', $range->fromSql());
    }

    public function testTheRequestFallsBackOnThirtyDaysWhenTheFreeDatesAreNot(): void
    {
        $range = DateRange::fromRequest(new Request(['period' => 'custom', 'from' => '2026-10-15', 'to' => '2026-10-01']), $this->today);

        self::assertSame(DateRange::PRESET_THIRTY_DAYS, $range->preset);
    }

    public function testTheRequestReadsAPresetAndDefaultsToThirtyDays(): void
    {
        self::assertSame(DateRange::PRESET_SEVEN_DAYS, DateRange::fromRequest(new Request(['period' => '7days']))->preset);
        self::assertSame(DateRange::PRESET_THIRTY_DAYS, DateRange::fromRequest(new Request())->preset);
    }

    public function testCustomIsNotOneOfThePills(): void
    {
        self::assertNotContains(DateRange::PRESET_CUSTOM, DateRange::ALLOWED_PRESETS);
    }
}

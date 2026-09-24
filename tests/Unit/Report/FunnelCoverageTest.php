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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Report\FunnelCoverage;
use PHPUnit\Framework\TestCase;

final class FunnelCoverageTest extends TestCase
{
    private const RETENTION_DAYS = 30;

    public function testAPeriodThatReachesBeforeTheOldestCartStartsOnTheDayOfThatCart(): void
    {
        $requested = $this->range('2026-01-01 00:00:00', '2026-09-24 23:59:59');

        $coverage = FunnelCoverage::resolve($requested, new \DateTimeImmutable('2026-08-25 14:12:00'), self::RETENTION_DAYS);

        self::assertTrue($coverage->truncated);
        self::assertEquals($requested->from, $coverage->requestedFrom);
        self::assertEquals(new \DateTimeImmutable('2026-08-25 00:00:00'), $coverage->from);
        self::assertEquals($requested->to, $coverage->to);
        self::assertSame(self::RETENTION_DAYS, $coverage->retentionDays);
    }

    public function testAPeriodThatStartsAfterTheOldestCartIsKeptAsRequested(): void
    {
        $requested = $this->range('2026-09-18 00:00:00', '2026-09-24 23:59:59');

        $coverage = FunnelCoverage::resolve($requested, new \DateTimeImmutable('2026-08-25 14:12:00'), self::RETENTION_DAYS);

        self::assertFalse($coverage->truncated);
        self::assertEquals($requested->from, $coverage->from);
        self::assertEquals($requested->from, $coverage->requestedFrom);
    }

    public function testAnOldestCartCreatedOnTheFirstDayOfThePeriodDoesNotTruncateIt(): void
    {
        $requested = $this->range('2026-09-18 00:00:00', '2026-09-24 23:59:59');

        $coverage = FunnelCoverage::resolve($requested, new \DateTimeImmutable('2026-09-18 09:30:00'), self::RETENTION_DAYS);

        self::assertFalse($coverage->truncated, 'The covered window starts at 00:00 of the oldest cart: the same day is fully covered.');
        self::assertEquals($requested->from, $coverage->from);
    }

    public function testAShopWithoutAnyCartKeepsThePeriodAsRequested(): void
    {
        $requested = $this->range('2026-01-01 00:00:00', '2026-09-24 23:59:59');

        $coverage = FunnelCoverage::resolve($requested, null, self::RETENTION_DAYS);

        self::assertFalse($coverage->truncated);
        self::assertEquals($requested->from, $coverage->from);
    }

    private function range(string $from, string $to): DateRange
    {
        return new DateRange(new \DateTimeImmutable($from), new \DateTimeImmutable($to), DateRange::PRESET_THIS_YEAR);
    }
}

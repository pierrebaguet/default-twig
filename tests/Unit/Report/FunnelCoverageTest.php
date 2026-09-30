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
use Thelia\Domain\Cart\Service\CartPurgeHorizon;

final class FunnelCoverageTest extends TestCase
{
    private const NOW = '2026-09-24 15:00:00';

    public function testAPeriodThatReachesBeforeThePurgeHorizonStartsOnTheHorizon(): void
    {
        $requested = $this->range('2026-01-01 00:00:00');

        $coverage = FunnelCoverage::resolve($requested, new CartPurgeHorizon(60, 30), new \DateTimeImmutable(self::NOW));

        self::assertTrue($coverage->truncated);
        self::assertEquals($requested->from, $coverage->requestedFrom);
        self::assertEquals(new \DateTimeImmutable('2026-08-25 15:00:00'), $coverage->from);
        self::assertEquals($requested->to, $coverage->to);
        self::assertSame(30, $coverage->retentionDays);
    }

    public function testAPeriodStartingAtMidnightOnTheHorizonDayIsCutToTheSecondOfThePurge(): void
    {
        $coverage = FunnelCoverage::resolve(
            $this->range('2026-08-25 00:00:00'),
            new CartPurgeHorizon(60, 30),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertTrue($coverage->truncated, 'The carts of 2026-08-25 before 15:00 are older than now - 30 days: the purge deletes them.');
        self::assertEquals(new \DateTimeImmutable('2026-08-25 15:00:00'), $coverage->from);
    }

    public function testAPeriodThatStartsAfterThePurgeHorizonIsKeptAsRequested(): void
    {
        $requested = $this->range('2026-09-18 00:00:00');

        $coverage = FunnelCoverage::resolve($requested, new CartPurgeHorizon(60, 30), new \DateTimeImmutable(self::NOW));

        self::assertFalse($coverage->truncated);
        self::assertEquals($requested->from, $coverage->from);
        self::assertEquals($requested->from, $coverage->requestedFrom);
    }

    public function testTheHorizonFollowsTheShorterOfTheTwoRetentions(): void
    {
        $coverage = FunnelCoverage::resolve(
            $this->range('2026-01-01 00:00:00'),
            new CartPurgeHorizon(10, 90),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertEquals(new \DateTimeImmutable('2026-09-14 15:00:00'), $coverage->from);
        self::assertSame(10, $coverage->retentionDays);
    }

    private function range(string $from): DateRange
    {
        return new DateRange(new \DateTimeImmutable($from), new \DateTimeImmutable('2026-09-24 23:59:59'), DateRange::PRESET_THIS_YEAR);
    }
}

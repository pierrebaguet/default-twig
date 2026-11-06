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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Reporting;

use BackOfficeDefaultTwigBundle\Service\Reporting\ReportSchedulingPanel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The crontab line the panel offers runs the reports on the expression the shop schedule
 * is given, and every 15 minutes when the shop schedule is left empty.
 */
final class ReportSchedulingPanelTest extends TestCase
{
    public function testTheCronLineFollowsTheConfiguredExpression(): void
    {
        $described = $this->panel('0 6 * * 1')->describe();

        self::assertSame('0 6 * * 1', $described['expression']);
        self::assertSame("0 6 * * 1 cd '/srv/shop' && php bin/console report:send-due", $described['cron_line']);
    }

    public function testTheCronLineRunsEveryFifteenMinutesWhenTheShopScheduleIsEmpty(): void
    {
        $described = $this->panel('')->describe();

        self::assertFalse($described['shop_schedule_enabled']);
        self::assertSame("*/15 * * * * cd '/srv/shop' && php bin/console report:send-due", $described['cron_line']);
    }

    private function panel(?string $expression): ReportSchedulingPanel
    {
        return new ReportSchedulingPanel(new ServiceLocator([]), '/srv/shop', $expression, $this->createStub(UrlGeneratorInterface::class));
    }
}

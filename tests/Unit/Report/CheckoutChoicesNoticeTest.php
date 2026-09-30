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

use BackOfficeDefaultTwigBundle\Service\Report\ConversionReportProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Report\ConversionFunnel\ConversionFunnel;

final class CheckoutChoicesNoticeTest extends TestCase
{
    public function testMoreOrdersThanCartsHoldingAPaymentModuleCallsForTheNotice(): void
    {
        self::assertTrue(ConversionReportProvider::ordersOutnumberCheckoutChoices(ConversionFunnel::fromCounts(40, 20, 0, 0, 9, 7)));
    }

    public function testANestedFunnelNeedsNoNotice(): void
    {
        self::assertFalse(ConversionReportProvider::ordersOutnumberCheckoutChoices(ConversionFunnel::fromCounts(40, 20, 12, 9, 9, 7)));
    }
}

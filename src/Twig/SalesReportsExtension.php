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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\Report\SalesReportsContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `sales_reports_available()`: whether the core carries the sales reports, to offer their
 * screen only then.
 */
final class SalesReportsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sales_reports_available', SalesReportsContext::coreHasSalesReports(...)),
        ];
    }
}

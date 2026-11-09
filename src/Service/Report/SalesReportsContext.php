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

namespace BackOfficeDefaultTwigBundle\Service\Report;

/**
 * The theme installs on a core that may predate the sales reports: the screen, its menu entry
 * and its routes exist only on a core that carries the calculator. The class is named by a
 * string, never by `::class` in a constant expression, so that a core without it is only a
 * `false` here.
 */
final class SalesReportsContext
{
    private const CALCULATOR = 'Thelia\Domain\Report\Sales\SalesReportCalculator';

    /** Names another class than the calculator, so that a test can play a core that lacks it. */
    private static ?string $calculatorOverride = null;

    public static function coreHasSalesReports(): bool
    {
        return class_exists(self::$calculatorOverride ?? self::CALCULATOR);
    }

    /**
     * @internal for the tests: null restores the real class
     */
    public static function pretendTheCalculatorIs(?string $class): void
    {
        self::$calculatorOverride = $class;
    }
}

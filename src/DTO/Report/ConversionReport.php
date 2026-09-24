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

namespace BackOfficeDefaultTwigBundle\DTO\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;

final readonly class ConversionReport
{
    /**
     * @param list<ConversionStepView>                                             $steps
     * @param list<array{value: string, label: string, active: bool, url: string}> $periodOptions
     */
    public function __construct(
        public array $steps,
        public ?float $conversionRatePercent,
        public string $conversionFormula,
        public array $periodOptions,
        public DateRange $range,
        public FunnelCoverage $coverage,
        public ?SearchLogReport $searchLog,
        public ?string $exportUrl,
    ) {
    }
}

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

final readonly class ConversionStepView
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public ?float $percentToPrevious,
        public ?float $percentToBase,
        public int $barWidth,
        public ?string $hint,
    ) {
    }
}

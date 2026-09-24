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

final readonly class SearchTerm
{
    public function __construct(
        public string $words,
        public string $locale,
        public int $hits,
        public ?int $searches,
    ) {
    }
}

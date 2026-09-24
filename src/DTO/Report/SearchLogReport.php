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

final readonly class SearchLogReport
{
    /**
     * @param list<SearchTerm> $zeroResultTerms
     * @param list<SearchTerm> $topTerms
     */
    public function __construct(
        public SearchLogAvailability $availability,
        public array $zeroResultTerms,
        public array $topTerms,
        public ?string $synonymsUrl,
    ) {
    }
}

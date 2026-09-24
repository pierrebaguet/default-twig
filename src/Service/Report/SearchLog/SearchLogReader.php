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

namespace BackOfficeDefaultTwigBundle\Service\Report\SearchLog;

use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogAvailability;
use BackOfficeDefaultTwigBundle\DTO\Report\SearchTerm;

interface SearchLogReader
{
    public function availability(): SearchLogAvailability;

    /**
     * @return list<SearchTerm>
     */
    public function topZeroResultTerms(int $limit = 50): array;

    /**
     * Empty unless availability() is WithCounter.
     *
     * @return list<SearchTerm>
     */
    public function topSearchedTerms(int $limit = 50): array;
}

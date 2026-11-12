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

namespace BackOfficeDefaultTwigBundle\Service\Search;

/**
 * One page of a group of search results, the most relevant first, and how many rows match in all.
 */
final readonly class SearchGroupResult
{
    /**
     * @param list<array<string, int|string|null>> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }

    public function isTruncated(): bool
    {
        return $this->total > \count($this->rows);
    }
}

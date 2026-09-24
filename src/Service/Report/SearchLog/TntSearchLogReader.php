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
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Reads the search log of the TntSearch module with plain SQL, since its Propel
 * classes only exist once the module is installed.
 *
 * The module upserts one row per words, locale and index and overwrites the
 * hits on every search; from 4.1 it also counts the searches. Rows are grouped
 * anyway, in case an older version left duplicates behind.
 */
final readonly class TntSearchLogReader implements SearchLogReader
{
    private const PRODUCT_INDEX = 'product';

    public function __construct(
        private ConnectionInterface $connection,
        private bool $hasSearchCount,
    ) {
    }

    public function availability(): SearchLogAvailability
    {
        return $this->hasSearchCount ? SearchLogAvailability::WithCounter : SearchLogAvailability::WithoutCounter;
    }

    public function topZeroResultTerms(int $limit = 50): array
    {
        $limit = max(1, $limit);

        if ($this->hasSearchCount) {
            return $this->read(
                'SELECT search_words, locale, SUM(search_count) AS searches, MAX(num_hits) AS hits'
                .$this->fromProductTerms()
                .' HAVING MAX(num_hits) = 0 ORDER BY searches DESC, search_words ASC LIMIT '.$limit,
            );
        }

        return $this->read(
            'SELECT search_words, locale, NULL AS searches, MAX(num_hits) AS hits'
            .$this->fromProductTerms()
            .' HAVING MAX(num_hits) = 0 ORDER BY search_words ASC LIMIT '.$limit,
        );
    }

    public function topSearchedTerms(int $limit = 50): array
    {
        $limit = max(1, $limit);

        if (!$this->hasSearchCount) {
            return [];
        }

        return $this->read(
            'SELECT search_words, locale, SUM(search_count) AS searches, MAX(num_hits) AS hits'
            .$this->fromProductTerms()
            .' ORDER BY searches DESC, search_words ASC LIMIT '.$limit,
        );
    }

    private function fromProductTerms(): string
    {
        return " FROM tnt_search_log WHERE `index` = '".self::PRODUCT_INDEX."'"
            ." AND search_words IS NOT NULL AND search_words <> ''"
            .' GROUP BY search_words, locale';
    }

    /**
     * @return list<SearchTerm>
     */
    private function read(string $sql): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $terms = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $terms[] = new SearchTerm(
                (string) $row['search_words'],
                (string) $row['locale'],
                (int) $row['hits'],
                null === $row['searches'] ? null : (int) $row['searches'],
            );
        }

        return $terms;
    }
}

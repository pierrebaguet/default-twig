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

use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Reads the schema rather than the module's Propel classes: those are only
 * generated once the module is installed, so the theme cannot reference them.
 */
final readonly class TntSearchSchemaProbe
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function tableExists(): bool
    {
        return $this->returnsARow(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tnt_search_log' LIMIT 1",
        );
    }

    public function hasSearchCountColumn(): bool
    {
        return $this->returnsARow(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tnt_search_log' AND COLUMN_NAME = 'search_count' LIMIT 1",
        );
    }

    private function returnsARow(string $sql): bool
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute();

        return false !== $statement->fetch(\PDO::FETCH_NUM);
    }
}

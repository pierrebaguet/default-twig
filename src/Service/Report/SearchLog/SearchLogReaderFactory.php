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
use Propel\Runtime\Propel;
use Thelia\Model\ModuleQuery;

final readonly class SearchLogReaderFactory
{
    private const MODULE_CODE = 'TntSearch';

    public function __construct(private TntSearchSchemaProbe $probe)
    {
    }

    public function create(): SearchLogReader
    {
        $module = ModuleQuery::create()->findOneByCode(self::MODULE_CODE);

        if (null === $module) {
            return new NullSearchLogReader(SearchLogAvailability::ModuleMissing);
        }

        if (!$module->getActivate()) {
            return new NullSearchLogReader(SearchLogAvailability::ModuleInactive);
        }

        if (!$this->probe->tableExists()) {
            return new NullSearchLogReader(SearchLogAvailability::TableMissing);
        }

        return new TntSearchLogReader(Propel::getConnection(), $this->probe->hasSearchCountColumn());
    }
}

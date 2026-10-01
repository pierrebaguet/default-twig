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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Module;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;

/**
 * Not readonly: the hook counts of the whole module list are read once and kept
 * for the request. The module list asks whether a module is configurable and
 * whether it is hookable for every row, twice over for the first question.
 */
final class ModuleRepository
{
    /** @var array<int, int>|null */
    private ?array $hookCounts = null;

    /** @var array<int, array<int, int>> */
    private array $configurationHookCounts = [];

    /** @var array<string, array<int, ?string>> */
    private array $titles = [];

    public function findOneByCode(string $code): ?Module
    {
        return ModuleQuery::create()->findOneByCode($code);
    }

    /**
     * @return ObjectCollection<int, Module>
     */
    public function findAllOrderedByPosition(string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($modules as $module) {
            $module->setLocale($locale);
        }

        return $modules;
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function findActiveByType(int $type, string $locale): array
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        $items = [];
        foreach ($modules as $module) {
            $module->setLocale($locale);
            $items[] = [
                'id' => (int) $module->getId(),
                'title' => (string) ($module->getTitle() ?: $module->getCode()),
                'code' => (string) $module->getCode(),
            ];
        }

        return $items;
    }

    /**
     * @return ObjectCollection<int, Module>
     */
    public function findActiveModulesByType(int $type, string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($modules as $module) {
            $module->setLocale($locale);
        }

        return $modules;
    }

    /**
     * @return list<int>
     */
    public function findActiveModuleIdsByType(int $type): array
    {
        $ids = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->select('Id')
            ->find()
            ->getData();

        return array_map('intval', $ids);
    }

    /**
     * Configuration entries of the given modules, read in one go and indexed by module id.
     *
     * @param list<int> $moduleIds
     *
     * @return array<int, array<string, string|null>>
     */
    public function findConfigurationEntries(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $entries = [];
        $configs = ModuleConfigQuery::create()
            ->filterByModuleId($moduleIds, Criteria::IN)
            ->find();

        foreach ($configs as $config) {
            $entries[(int) $config->getModuleId()][(string) $config->getName()] = $config->getValue();
        }

        return $entries;
    }

    /**
     * Module titles indexed by id, for the lists naming a module per row. A module
     * with no title in that locale answers null, the way getTitle() does, so a
     * caller can fall back on what it froze at the time.
     *
     * @return array<int, ?string>
     */
    public function findLocalizedTitles(string $locale): array
    {
        if (isset($this->titles[$locale])) {
            return $this->titles[$locale];
        }

        $titles = [];
        $modules = ModuleQuery::create()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($modules as $module) {
            $module->setLocale($locale);
            $titles[(int) $module->getId()] = $module->getTitle();
        }

        return $this->titles[$locale] = $titles;
    }

    /**
     * How many hooks each module registers, all modules in one query.
     *
     * @return array<int, int>
     */
    public function countHooksByModule(): array
    {
        return $this->hookCounts ??= $this->groupedCounts(ModuleHookQuery::create());
    }

    /**
     * How many active configuration hooks of that template type each module
     * registers, all modules in one query.
     *
     * @return array<int, int>
     */
    public function countActiveConfigurationHooksByModule(int $hookType): array
    {
        return $this->configurationHookCounts[$hookType] ??= $this->groupedCounts(
            ModuleHookQuery::create()
                ->filterByActive(true)
                ->useHookQuery()
                    ->filterByCode('module.configuration')
                    ->filterByType($hookType)
                ->endUse(),
        );
    }

    /**
     * @return array<int, int>
     */
    private function groupedCounts(ModuleHookQuery $query): array
    {
        $counts = [];
        $rows = $query
            ->withColumn('COUNT(*)', 'hook_count')
            ->groupByModuleId()
            ->select(['ModuleId', 'hook_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['ModuleId']] = (int) $row['hook_count'];
        }

        return $counts;
    }
}

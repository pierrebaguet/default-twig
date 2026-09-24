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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Report\SearchLog;

use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogAvailability;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\NullSearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\SearchLogReaderFactory;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\TntSearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\TntSearchSchemaProbe;
use Propel\Runtime\Propel;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The report reads the search log of the TntSearch module, which a shop may
 * not have installed, may have switched off, or may not have set up yet. Every
 * one of those states must reach the screen as a state, never as an SQL error.
 */
final class SearchLogReaderFactoryTest extends IntegrationTestCase
{
    private const MODULE_CODE = 'TntSearch';

    public function testAShopWithoutTheModuleGetsAReaderThatSaysSo(): void
    {
        // The row is renamed rather than deleted: the rename stays inside the
        // test transaction and does not cascade to the hooks of the module.
        $this->propelConnection()->exec("UPDATE module SET code = 'TntSearchHiddenByTest' WHERE code = 'TntSearch'");

        $reader = $this->factory()->create();

        self::assertInstanceOf(NullSearchLogReader::class, $reader);
        self::assertSame(SearchLogAvailability::ModuleMissing, $reader->availability());
        self::assertSame([], $reader->topZeroResultTerms());
        self::assertSame([], $reader->topSearchedTerms());
    }

    public function testAnInstalledButDeactivatedModuleGetsAReaderThatSaysSo(): void
    {
        $this->givenTheModule(activated: false);

        $reader = $this->factory()->create();

        self::assertInstanceOf(NullSearchLogReader::class, $reader);
        self::assertSame(SearchLogAvailability::ModuleInactive, $reader->availability());
    }

    public function testAnActiveModuleWithoutItsTableGetsAReaderThatSaysSo(): void
    {
        if ($this->probe()->tableExists()) {
            self::markTestSkipped('The tnt_search_log table exists in the test database: dropping it would commit the test transaction.');
        }

        $this->givenTheModule(activated: true);

        $reader = $this->factory()->create();

        self::assertInstanceOf(NullSearchLogReader::class, $reader);
        self::assertSame(SearchLogAvailability::TableMissing, $reader->availability());
    }

    public function testAnActiveModuleWithItsTableGetsTheRealReader(): void
    {
        if (!$this->probe()->tableExists()) {
            self::markTestSkipped('The tnt_search_log table is absent from the test database.');
        }

        $this->givenTheModule(activated: true);

        $reader = $this->factory()->create();

        self::assertInstanceOf(TntSearchLogReader::class, $reader);
        self::assertSame(
            $this->probe()->hasSearchCountColumn() ? SearchLogAvailability::WithCounter : SearchLogAvailability::WithoutCounter,
            $reader->availability(),
        );
    }

    private function givenTheModule(bool $activated): void
    {
        $module = ModuleQuery::create()->findOneByCode(self::MODULE_CODE) ?? (new Module())
            ->setCode(self::MODULE_CODE)
            ->setType(1)
            ->setVersion('4.0.0')
            ->setFullNamespace('TntSearch\\TntSearch');

        $module->setActivate($activated ? 1 : 0)->save();
    }

    private function factory(): SearchLogReaderFactory
    {
        return new SearchLogReaderFactory($this->probe());
    }

    private function probe(): TntSearchSchemaProbe
    {
        return new TntSearchSchemaProbe($this->propelConnection());
    }

    private function propelConnection(): \Propel\Runtime\Connection\ConnectionInterface
    {
        return Propel::getConnection();
    }
}

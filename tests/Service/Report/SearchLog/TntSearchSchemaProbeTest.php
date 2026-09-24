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

use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\TntSearchSchemaProbe;
use Propel\Runtime\Propel;
use Thelia\Test\IntegrationTestCase;

/**
 * The probe reads information_schema. Its answers are checked against SHOW
 * TABLES / SHOW COLUMNS, a different path to the same schema, so a typo in the
 * probe query cannot agree with itself.
 */
final class TntSearchSchemaProbeTest extends IntegrationTestCase
{
    public function testTheProbeSeesTheTableExactlyWhenTheSchemaHasIt(): void
    {
        self::assertSame($this->tableIsInTheSchema(), $this->probe()->tableExists());
    }

    public function testTheProbeSeesTheCounterColumnExactlyWhenTheSchemaHasIt(): void
    {
        $expected = $this->tableIsInTheSchema()
            && false !== Propel::getConnection()->query("SHOW COLUMNS FROM tnt_search_log LIKE 'search_count'")->fetch();

        self::assertSame($expected, $this->probe()->hasSearchCountColumn());
    }

    public function testWithoutTheTableTheProbeAnswersNoToBothQuestions(): void
    {
        if ($this->tableIsInTheSchema()) {
            self::markTestSkipped('The tnt_search_log table exists in the test database.');
        }

        self::assertFalse($this->probe()->tableExists());
        self::assertFalse($this->probe()->hasSearchCountColumn());
    }

    public function testWithTheTableTheProbeSaysItExists(): void
    {
        if (!$this->tableIsInTheSchema()) {
            self::markTestSkipped('The tnt_search_log table is absent from the test database.');
        }

        self::assertTrue($this->probe()->tableExists());
    }

    private function tableIsInTheSchema(): bool
    {
        return false !== Propel::getConnection()->query("SHOW TABLES LIKE 'tnt_search_log'")->fetch();
    }

    private function probe(): TntSearchSchemaProbe
    {
        return new TntSearchSchemaProbe(Propel::getConnection());
    }
}

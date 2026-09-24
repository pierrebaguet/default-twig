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
use BackOfficeDefaultTwigBundle\DTO\Report\SearchTerm;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\TntSearchLogReader;
use Propel\Runtime\Propel;
use Thelia\Test\IntegrationTestCase;

/**
 * The reader runs against a temporary tnt_search_log created on the connection
 * it is given. A temporary table shadows a permanent one of the same name for
 * that session only, does not commit the test transaction, and is dropped
 * before the rollback: the real table of the module, when it is installed, is
 * never read nor written.
 */
final class TntSearchLogReaderTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Propel::getConnection()->exec(
            'CREATE TEMPORARY TABLE tnt_search_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                search_words VARCHAR(255),
                `index` VARCHAR(255),
                locale VARCHAR(255),
                num_hits INT,
                search_count INT NOT NULL DEFAULT 1
            )',
        );
    }

    protected function tearDown(): void
    {
        Propel::getConnection()->exec('DROP TEMPORARY TABLE IF EXISTS tnt_search_log');

        parent::tearDown();
    }

    public function testTheAvailabilityFollowsTheCounterColumn(): void
    {
        self::assertSame(SearchLogAvailability::WithCounter, $this->reader(hasSearchCount: true)->availability());
        self::assertSame(SearchLogAvailability::WithoutCounter, $this->reader(hasSearchCount: false)->availability());
    }

    public function testWithTheCounterTheZeroResultTermsComeMostSearchedFirst(): void
    {
        $this->givenTheLog();

        self::assertEquals(
            [
                new SearchTerm('chaussure', 'fr_FR', 0, 5),
                new SearchTerm('sandale', 'fr_FR', 0, 2),
                new SearchTerm('<b>x</b>', 'fr_FR', 0, 1),
            ],
            $this->reader(hasSearchCount: true)->topZeroResultTerms(),
        );
    }

    public function testWithoutTheCounterTheZeroResultTermsComeAlphabeticallyWithNoCount(): void
    {
        $this->givenTheLog();

        self::assertEquals(
            [
                new SearchTerm('<b>x</b>', 'fr_FR', 0, null),
                new SearchTerm('chaussure', 'fr_FR', 0, null),
                new SearchTerm('sandale', 'fr_FR', 0, null),
            ],
            $this->reader(hasSearchCount: false)->topZeroResultTerms(),
        );
    }

    public function testTheSameTermLoggedTwiceIsOneLineWithItsSearchesAdded(): void
    {
        $this->insert('chaussure', 'product', 'fr_FR', 0, 5);
        $this->insert('chaussure', 'product', 'fr_FR', 0, 4);

        self::assertEquals(
            [new SearchTerm('chaussure', 'fr_FR', 0, 9)],
            $this->reader(hasSearchCount: true)->topZeroResultTerms(),
        );
    }

    public function testWithoutTheCounterThereAreNoMostSearchedTerms(): void
    {
        $this->givenTheLog();

        self::assertSame([], $this->reader(hasSearchCount: false)->topSearchedTerms());
    }

    public function testWithTheCounterTheMostSearchedTermsIncludeTheOnesWithResults(): void
    {
        $this->givenTheLog();

        self::assertEquals(
            [
                new SearchTerm('botte', 'fr_FR', 3, 8),
                new SearchTerm('chaussure', 'fr_FR', 0, 5),
                new SearchTerm('sandale', 'fr_FR', 0, 2),
                new SearchTerm('<b>x</b>', 'fr_FR', 0, 1),
            ],
            $this->reader(hasSearchCount: true)->topSearchedTerms(),
        );
    }

    public function testTheLimitIsRespected(): void
    {
        $this->givenTheLog();

        $reader = $this->reader(hasSearchCount: true);

        self::assertCount(2, $reader->topZeroResultTerms(2));
        self::assertCount(1, $reader->topSearchedTerms(1));
        self::assertSame('botte', $reader->topSearchedTerms(1)[0]->words);
        self::assertCount(2, $this->reader(hasSearchCount: false)->topZeroResultTerms(2));
    }

    public function testRunningTheClassTwiceStartsFromAnEmptyLog(): void
    {
        self::assertSame([], $this->reader(hasSearchCount: true)->topSearchedTerms());
    }

    private function givenTheLog(): void
    {
        $this->insert('chaussure', 'product', 'fr_FR', 0, 5);
        $this->insert('sandale', 'product', 'fr_FR', 0, 2);
        $this->insert('botte', 'product', 'fr_FR', 3, 8);
        // Another index: a category nobody finds is not a missing product.
        $this->insert('rayon', 'category', 'fr_FR', 0, 50);
        // Rows without words carry nothing to show.
        $this->insert('', 'product', 'fr_FR', 0, 40);
        $this->insert(null, 'product', 'fr_FR', 0, 30);
        // User input, returned verbatim: escaping is the template's job.
        $this->insert('<b>x</b>', 'product', 'fr_FR', 0, 1);
    }

    private function insert(?string $words, string $index, string $locale, int $hits, int $searches): void
    {
        $statement = Propel::getConnection()->prepare(
            'INSERT INTO tnt_search_log (search_words, `index`, locale, num_hits, search_count) VALUES (:words, :index, :locale, :hits, :searches)',
        );
        $statement->execute([':words' => $words, ':index' => $index, ':locale' => $locale, ':hits' => $hits, ':searches' => $searches]);
    }

    private function reader(bool $hasSearchCount): TntSearchLogReader
    {
        return new TntSearchLogReader(Propel::getConnection(), $hasSearchCount);
    }
}

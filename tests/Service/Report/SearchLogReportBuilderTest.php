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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Report;

use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogAvailability;
use BackOfficeDefaultTwigBundle\DTO\Report\SearchTerm;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\NullSearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\SearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLogReportBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Test\IntegrationTestCase;

final class SearchLogReportBuilderTest extends IntegrationTestCase
{
    public function testAnUnreadableLogOffersNoSynonymsLink(): void
    {
        foreach ([SearchLogAvailability::ModuleMissing, SearchLogAvailability::ModuleInactive, SearchLogAvailability::TableMissing] as $availability) {
            $report = $this->builder(new NullSearchLogReader($availability))->build();

            self::assertSame($availability, $report->availability);
            self::assertSame([], $report->zeroResultTerms);
            self::assertSame([], $report->topTerms);
            self::assertNull($report->synonymsUrl);
        }
    }

    public function testAReadableLogCarriesItsTermsAndTheSynonymsLink(): void
    {
        $term = new SearchTerm('chaussure', 'fr_FR', 0, 5);
        $reader = new class($term) implements SearchLogReader {
            public int $limit = 0;

            public function __construct(private readonly SearchTerm $term)
            {
            }

            public function availability(): SearchLogAvailability
            {
                return SearchLogAvailability::WithCounter;
            }

            public function topZeroResultTerms(int $limit = 50): array
            {
                $this->limit = $limit;

                return [$this->term];
            }

            public function topSearchedTerms(int $limit = 50): array
            {
                return [$this->term, $this->term];
            }
        };

        $report = $this->builder($reader)->build(7);

        self::assertSame(7, $reader->limit);
        self::assertSame([$term], $report->zeroResultTerms);
        self::assertSame([$term, $term], $report->topTerms);
        self::assertSame('/admin/module/TntSearch/synonym', $report->synonymsUrl);
    }

    private function builder(SearchLogReader $reader): SearchLogReportBuilder
    {
        return new SearchLogReportBuilder($reader, $this->getService(UrlGeneratorInterface::class));
    }
}

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
use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogReport;
use BackOfficeDefaultTwigBundle\DTO\Report\SearchTerm;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * The partial of the "Searches without result" tab, rendered for each state of
 * the search log. The terms are typed by visitors: they must come out escaped.
 */
final class SearchLogPartialTest extends IntegrationTestCase
{
    private const TEMPLATE = '@BackOfficeDefaultTwig/reports/_search_log.html.twig';

    private const SYNONYMS_URL = '/admin/module/TntSearch/synonym';

    public function testEachUnreadableStateSaysWhyWithoutATableNorASynonymsLink(): void
    {
        $messages = [
            'module_missing' => 'Install and activate the TntSearch module to see the searches without result',
            'module_inactive' => 'The TntSearch module is installed but not activated',
            'table_missing' => 'The TntSearch module has no search log yet',
        ];

        foreach ($messages as $state => $message) {
            $html = $this->render(new SearchLogReport(SearchLogAvailability::from($state), [], [], null));

            self::assertStringContainsString('data-testid="report-search-log"', $html);
            self::assertStringContainsString('data-testid="report-search-log-state-'.$state.'"', $html);
            self::assertStringContainsString($message, $html);
            self::assertStringNotContainsString('<table', $html);
            self::assertStringNotContainsString('report-search-synonyms-link', $html);
        }

        self::assertStringContainsString(
            'href="/admin/modules"',
            $this->render(new SearchLogReport(SearchLogAvailability::ModuleMissing, [], [], null)),
        );
    }

    public function testWithoutTheCounterOneTableOfHitsAndAnInvitationToUpdate(): void
    {
        $html = $this->render(new SearchLogReport(
            SearchLogAvailability::WithoutCounter,
            [new SearchTerm('<b>x</b>', 'fr_FR', 0, null)],
            [],
            self::SYNONYMS_URL,
        ));

        self::assertStringContainsString('data-testid="report-search-log-state-without_counter"', $html);
        self::assertStringContainsString('data-testid="report-search-log-zero-table"', $html);
        self::assertStringNotContainsString('report-search-log-top-table', $html);
        self::assertStringContainsString('update the module to 4.1 to see the counts', $html);
        self::assertStringContainsString('href="'.self::SYNONYMS_URL.'"', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testWithTheCounterTheSearchesAndTheMostSearchedTerms(): void
    {
        $html = $this->render(new SearchLogReport(
            SearchLogAvailability::WithCounter,
            [new SearchTerm('<b>x</b>', 'fr_FR', 0, 17)],
            [new SearchTerm('botte', 'fr_FR', 3, 8)],
            self::SYNONYMS_URL,
        ));

        self::assertStringContainsString('data-testid="report-search-log-state-with_counter"', $html);
        self::assertStringContainsString('data-testid="report-search-log-zero-table"', $html);
        self::assertStringContainsString('data-testid="report-search-log-top-table"', $html);
        self::assertStringContainsString('data-testid="report-search-synonyms-link"', $html);
        self::assertStringContainsString('>17<', $html);
        self::assertStringContainsString('botte', $html);
        self::assertStringNotContainsString('update the module to 4.1', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testEmptyListsSayNothingWasLogged(): void
    {
        $html = $this->render(new SearchLogReport(SearchLogAvailability::WithCounter, [], [], self::SYNONYMS_URL));

        self::assertStringContainsString('data-testid="report-search-log-zero-empty"', $html);
        self::assertStringContainsString('data-testid="report-search-log-top-empty"', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    private function render(SearchLogReport $report): string
    {
        return $this->getService(Environment::class)->render(self::TEMPLATE, ['search_log' => $report]);
    }
}

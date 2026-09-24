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

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Report\ConversionStepView;
use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogAvailability;
use BackOfficeDefaultTwigBundle\Repository\CartRepository;
use BackOfficeDefaultTwigBundle\Service\Dashboard\PeriodOptions;
use BackOfficeDefaultTwigBundle\Service\Report\ConversionReportProvider;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\NullSearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLogReportBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Cart\Service\CartPurgeHorizon;
use Thelia\Domain\Report\ConversionFunnel\ConversionFunnelCalculator;
use Thelia\Test\IntegrationTestCase;

final class ConversionReportProviderTest extends IntegrationTestCase
{
    private const LOCALE = 'fr_FR';

    private SecurityContext $securityContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->securityContext = $this->getService(SecurityContext::class);

        // The theme catalogue is registered by BackOfficeTranslationListener on
        // the /admin requests only; a service test has no such request.
        Translator::getInstance()->addResource(
            'php',
            \dirname(__DIR__, 3).'/translations/messages.fr_FR.php',
            self::LOCALE,
            'core',
        );
    }

    protected function tearDown(): void
    {
        $this->securityContext->clearAdminUser();

        parent::tearDown();
    }

    public function testTheSixStepsComeInTheOrderOfTheFunnelWithTranslatedLabels(): void
    {
        $this->securityContext->setAdminUser($this->createFixtureFactory()->admin());

        $report = $this->provider()->compute(DateRange::fromPreset(DateRange::PRESET_THIRTY_DAYS), self::LOCALE);

        self::assertSame(
            ['carts_created', 'carts_with_items', 'carts_with_delivery', 'carts_with_payment', 'orders_created', 'orders_paid'],
            array_map(static fn (ConversionStepView $step): string => $step->key, $report->steps),
        );

        $translator = $this->getService(TranslatorInterface::class);
        $expectedLabels = [
            $translator->trans('Carts created', [], null, self::LOCALE),
            $translator->trans('Carts with at least one line', [], null, self::LOCALE),
            $translator->trans('Delivery module chosen', [], null, self::LOCALE),
            $translator->trans('Payment module chosen', [], null, self::LOCALE),
            $translator->trans('Orders placed', [], null, self::LOCALE),
            $translator->trans('Orders paid', [], null, self::LOCALE),
        ];
        self::assertSame($expectedLabels, array_map(static fn (ConversionStepView $step): string => $step->label, $report->steps));
        self::assertSame('Paniers créés', $report->steps[0]->label, 'The labels come from the French catalogue.');

        self::assertNull($report->steps[0]->percentToPrevious);
        foreach ($report->steps as $step) {
            self::assertGreaterThanOrEqual(0, $step->barWidth);
            self::assertLessThanOrEqual(100, $step->barWidth);
        }

        self::assertNull($report->steps[0]->hint);
        self::assertNotNull($report->steps[2]->hint);
        self::assertNotNull($report->steps[3]->hint);
        self::assertNull($report->steps[4]->hint);

        self::assertCount(\count(DateRange::ALLOWED_PRESETS), $report->periodOptions);
        self::assertStringContainsString('/admin/reports/conversion?period=', $report->periodOptions[0]['url']);
        self::assertNotNull($report->searchLog, 'A full administrator gets the search log.');
        self::assertNotNull($report->exportUrl, 'A full administrator gets the export link.');
    }

    public function testTheCoverageStartsOnTheOldestCartStillInTheDatabase(): void
    {
        $oldestCart = (new CartRepository())->oldestCartCreatedAt();
        $retentionDays = CartPurgeHorizon::fromConfig()->retentionDays();

        foreach ([DateRange::PRESET_TODAY, DateRange::PRESET_THIS_YEAR] as $preset) {
            $range = DateRange::fromPreset($preset);
            $coverage = $this->provider()->compute($range, self::LOCALE)->coverage;

            self::assertEquals($range->from, $coverage->requestedFrom);
            self::assertEquals($range->to, $coverage->to);
            self::assertSame($retentionDays, $coverage->retentionDays);
            self::assertSame(
                null !== $oldestCart && $oldestCart->setTime(0, 0) > $range->from,
                $coverage->truncated,
                \sprintf('"%s": truncated only when the oldest cart is later than the start of the period.', $preset),
            );
            self::assertEquals($coverage->truncated ? $oldestCart?->setTime(0, 0) : $range->from, $coverage->from);
        }
    }

    /**
     * The test database may hold no cart at all. A cart created now, inside the
     * rolled-back transaction, is then the oldest one: the year is cut to today.
     */
    public function testAYearThatReachesBeforeTheOldestCartIsComputedFromThatCart(): void
    {
        if (null !== (new CartRepository())->oldestCartCreatedAt()) {
            self::markTestSkipped('The test database already holds carts: the oldest one is not controlled by this test.');
        }

        $this->createFixtureFactory()->cart();
        $range = DateRange::fromPreset(DateRange::PRESET_THIS_YEAR);

        $coverage = $this->provider()->compute($range, self::LOCALE)->coverage;

        self::assertTrue($coverage->truncated);
        self::assertEquals($range->from, $coverage->requestedFrom);
        self::assertEquals((new \DateTimeImmutable())->setTime(0, 0), $coverage->from);
    }

    public function testThePeriodPillsKeepTheSearchesTabOpen(): void
    {
        $this->securityContext->setAdminUser($this->createFixtureFactory()->admin());
        $range = DateRange::fromPreset(DateRange::PRESET_THIRTY_DAYS);

        $search = $this->provider()->compute($range, self::LOCALE, 'search');
        self::assertStringContainsString('current_tab=search', $search->periodOptions[0]['url']);

        $funnel = $this->provider()->compute($range, self::LOCALE);
        self::assertStringNotContainsString('current_tab', $funnel->periodOptions[0]['url'], 'The funnel is the default tab.');
    }

    public function testNeitherTheSearchLogNorTheExportWithoutTheirPermission(): void
    {
        $report = $this->provider()->compute(DateRange::fromPreset(DateRange::PRESET_THIRTY_DAYS), self::LOCALE);
        self::assertNull($report->searchLog, 'No administrator, no search log.');
        self::assertNull($report->exportUrl);

        $this->securityContext->setAdminUser($this->createFixtureFactory()->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
        ]));

        $report = $this->provider()->compute(DateRange::fromPreset(DateRange::PRESET_THIRTY_DAYS), self::LOCALE);
        self::assertNull($report->searchLog, 'The search log follows the product permission.');
        self::assertNull($report->exportUrl, 'The export link follows the export permission.');
    }

    public function testThePeriodOptionsOfTheDashboardStillLinkToTheHomePage(): void
    {
        $options = $this->periodOptions()->build(DateRange::fromPreset(DateRange::PRESET_SEVEN_DAYS), 'admin.home');

        self::assertSame(DateRange::ALLOWED_PRESETS, array_column($options, 'value'));
        self::assertSame(['7days'], array_column(array_filter($options, static fn (array $option): bool => $option['active']), 'value'));
        self::assertStringEndsWith('/admin/home?period=today', $options[0]['url']);
    }

    private function provider(): ConversionReportProvider
    {
        $urls = $this->getService(UrlGeneratorInterface::class);

        return new ConversionReportProvider(
            new ConversionFunnelCalculator(),
            new CartRepository(),
            $this->periodOptions(),
            new SearchLogReportBuilder(new NullSearchLogReader(SearchLogAvailability::ModuleMissing), $urls),
            $this->securityContext,
            $this->getService(TranslatorInterface::class),
            $urls,
        );
    }

    private function periodOptions(): PeriodOptions
    {
        return new PeriodOptions($this->getService(UrlGeneratorInterface::class), $this->getService(TranslatorInterface::class));
    }
}

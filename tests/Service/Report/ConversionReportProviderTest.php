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
use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
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
use Thelia\Domain\Cart\Service\CartPurger;
use Thelia\Domain\Report\ConversionFunnel\ConversionFunnelCalculator;
use Thelia\Model\CartQuery;
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
        self::assertNull($report->steps[4]->percentToPrevious, 'Orders are not compared with the payment step, a lower bound.');
        self::assertNotNull($report->steps[4]->hint);
        self::assertNull($report->steps[5]->hint);

        self::assertCount(\count(DateRange::ALLOWED_PRESETS), $report->periodOptions);
        self::assertStringContainsString('/admin/reports/conversion?period=', $report->periodOptions[0]['url']);
        self::assertNotNull($report->searchLog, 'A full administrator gets the search log.');
        self::assertNotNull($report->exportUrl, 'A full administrator gets the export link.');
    }

    public function testTodayIsNotCutByThePurgeHorizon(): void
    {
        $range = DateRange::fromPreset(DateRange::PRESET_TODAY);

        $coverage = $this->provider()->compute($range, self::LOCALE)->coverage;

        self::assertFalse($coverage->truncated);
        self::assertEquals($range->from, $coverage->from);
        self::assertEquals($range->to, $coverage->to);
    }

    /**
     * A cart that led to an order is never purged, so the oldest cart of a shop
     * says nothing about the carts without order the purge already deleted.
     */
    public function testAPeriodReachingBeforeThePurgeHorizonIsCutThereEvenWhenAnOrderedCartIsOlder(): void
    {
        $connection = $this->getPropelConnection();
        $factory = $this->createFixtureFactory();
        $horizon = CartPurgeHorizon::fromConfig();
        $earliestSurvivingBefore = $horizon->earliestSurvivingCartDate();

        $order = $factory->order();
        CartQuery::create()->findPk($order->getCartId(), $connection)
            ->setCreatedAt(\DateTime::createFromImmutable($earliestSurvivingBefore->modify('-200 days')))
            ->save($connection);

        $from = $earliestSurvivingBefore->modify('-10 days')->setTime(0, 0);
        $abandoned = $factory->cart();
        $abandoned->setCreatedAt(\DateTime::createFromImmutable($from->modify('+1 day')))->save($connection);
        (new CartPurger())->purgeAnonymousCarts($horizon->retentionDays());
        self::assertNull(CartQuery::create()->findPk($abandoned->getId(), $connection), 'Precondition: the purge deleted the abandoned cart.');

        $range = new DateRange($from, (new \DateTimeImmutable())->setTime(23, 59, 59), DateRange::PRESET_NINETY_DAYS);
        $coverage = $this->provider()->compute($range, self::LOCALE)->coverage;
        $earliestSurvivingAfter = $horizon->earliestSurvivingCartDate();

        self::assertTrue($coverage->truncated, 'The period starts before the purge horizon: its carts without order are gone.');
        self::assertEquals($range->from, $coverage->requestedFrom);
        self::assertGreaterThanOrEqual($earliestSurvivingBefore, $coverage->from);
        self::assertLessThanOrEqual($earliestSurvivingAfter, $coverage->from);
        self::assertSame($horizon->retentionDays(), $coverage->retentionDays);
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
            $this->getService(DataTransferRepository::class),
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

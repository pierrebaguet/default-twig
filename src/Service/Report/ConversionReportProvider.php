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

namespace BackOfficeDefaultTwigBundle\Service\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Report\ConversionReport;
use BackOfficeDefaultTwigBundle\DTO\Report\ConversionStepView;
use BackOfficeDefaultTwigBundle\DTO\Report\FunnelCoverage;
use BackOfficeDefaultTwigBundle\Repository\CartRepository;
use BackOfficeDefaultTwigBundle\Service\Dashboard\PeriodOptions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Cart\Service\CartPurgeHorizon;
use Thelia\Domain\Report\ConversionFunnel\ConversionFunnelCalculator;
use Thelia\Domain\Report\ConversionFunnel\FunnelStep;
use Thelia\Model\Export;
use Thelia\Model\ExportQuery;

/**
 * The "Reports > Conversion" screen: the checkout funnel of the period, and the
 * searches without result for an administrator allowed on the catalog.
 */
final readonly class ConversionReportProvider
{
    public const ROUTE = 'admin.reports.conversion';

    public const TAB_FUNNEL = 'funnel';

    public const TAB_SEARCH = 'search';

    private const EXPORT_REF = 'thelia.export.conversion_funnel';

    private const STEP_LABELS = [
        FunnelStep::CARTS_CREATED => 'Carts created',
        FunnelStep::CARTS_WITH_ITEMS => 'Carts with at least one line',
        FunnelStep::CARTS_WITH_DELIVERY => 'Delivery module chosen',
        FunnelStep::CARTS_WITH_PAYMENT => 'Payment module chosen',
        FunnelStep::ORDERS_CREATED => 'Orders placed',
        FunnelStep::ORDERS_PAID => 'Orders paid',
    ];

    /**
     * The cart forgets the delivery and payment modules when the shopper goes
     * back to the cart page: these two steps only count the carts that still
     * hold the choice.
     */
    private const LOWER_BOUND_STEPS = [
        FunnelStep::CARTS_WITH_DELIVERY,
        FunnelStep::CARTS_WITH_PAYMENT,
    ];

    private const LOWER_BOUND_HINT = 'Lower bound: the choice is cleared when the shopper goes back to the cart page.';

    private const ORDERS_HINT = 'Compared with the carts holding a line: the previous step is a lower bound.';

    public function __construct(
        private ConversionFunnelCalculator $calculator,
        private CartRepository $carts,
        private PeriodOptions $periodOptions,
        private SearchLogReportBuilder $searchLogReportBuilder,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function compute(DateRange $range, string $locale, string $currentTab = self::TAB_FUNNEL): ConversionReport
    {
        $coverage = FunnelCoverage::resolve(
            $range,
            $this->carts->oldestCartCreatedAt(),
            CartPurgeHorizon::fromConfig()->retentionDays(),
        );
        $funnel = $this->calculator->total($coverage->from, $coverage->to);
        $searchLog = $this->canView(AdminResources::PRODUCT) ? $this->searchLogReportBuilder->build() : null;

        $steps = [];
        foreach ($funnel->steps as $step) {
            $percentToBase = $step->percentToBase();

            $steps[] = new ConversionStepView(
                key: $step->key,
                label: $this->translator->trans(self::STEP_LABELS[$step->key], [], null, $locale),
                count: $step->count,
                percentToPrevious: FunnelStep::ORDERS_CREATED === $step->key ? null : $step->percentToPrevious(),
                percentToBase: $percentToBase,
                barWidth: null === $percentToBase ? 0 : (int) round(max(0.0, min(100.0, $percentToBase))),
                hint: $this->stepHint($step->key, $locale),
            );
        }

        return new ConversionReport(
            steps: $steps,
            conversionRatePercent: $funnel->conversionRatePercent(),
            conversionFormula: $this->translator->trans('Paid orders ÷ carts with at least one line', [], null, $locale),
            periodOptions: $this->periodOptions->build(
                $range,
                self::ROUTE,
                self::TAB_SEARCH === $currentTab && null !== $searchLog ? ['current_tab' => self::TAB_SEARCH] : [],
            ),
            range: $range,
            coverage: $coverage,
            searchLog: $searchLog,
            exportUrl: $this->exportUrl(),
        );
    }

    private function stepHint(string $key, string $locale): ?string
    {
        if (\in_array($key, self::LOWER_BOUND_STEPS, true)) {
            return $this->translator->trans(self::LOWER_BOUND_HINT, [], null, $locale);
        }

        if (FunnelStep::ORDERS_CREATED === $key) {
            return $this->translator->trans(self::ORDERS_HINT, [], null, $locale);
        }

        return null;
    }

    private function exportUrl(): ?string
    {
        if (!$this->canView(AdminResources::EXPORT)) {
            return null;
        }

        $export = ExportQuery::create()->findOneByRef(self::EXPORT_REF);

        if (!$export instanceof Export) {
            return null;
        }

        return $this->urls->generate('export.view', ['id' => $export->getId()]);
    }

    private function canView(string $resource): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [$resource], [], [AccessManager::VIEW]);
    }
}

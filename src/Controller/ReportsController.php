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

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Report\ConversionReportProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

final class ReportsController
{
    private const TABS = [ConversionReportProvider::TAB_FUNNEL, ConversionReportProvider::TAB_SEARCH];

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly ConversionReportProvider $conversionReports,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/reports/conversion', name: ConversionReportProvider::ROUTE, methods: ['GET'])]
    public function conversion(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return $denied;
        }

        $range = DateRange::fromPreset((string) $request->query->get('period', DateRange::PRESET_THIRTY_DAYS));
        $currentTab = (string) $request->query->get('current_tab', ConversionReportProvider::TAB_FUNNEL);
        if (!\in_array($currentTab, self::TABS, true)) {
            $currentTab = ConversionReportProvider::TAB_FUNNEL;
        }

        $report = $this->conversionReports->compute($range, $request->getLocale(), $currentTab);

        if ($report->searchLog === null) {
            $currentTab = ConversionReportProvider::TAB_FUNNEL;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/reports/conversion.html.twig', [
            'report' => $report,
            'current_tab' => $currentTab,
        ]));
    }
}

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
use BackOfficeDefaultTwigBundle\DTO\Report\SalesReport;
use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Dashboard\PeriodOptions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;

/**
 * The "Reports > Sales" screen: the sales of a period by category, by product and by customer,
 * and how they add up to the revenue of the dashboard. The figures are the core's
 * (Thelia\Domain\Report\Sales\SalesReportCalculator): the theme holds no SQL of a report.
 *
 * The theme installs on a core that may predate the calculator: it is built only once the core is
 * known to carry it, and named by a string, so that a core without it never loads it.
 */
final readonly class SalesReportProvider
{
    public const ROUTE_CATEGORY = 'admin.reports.sales.category';

    public const ROUTE_PRODUCT = 'admin.reports.sales.product';

    public const ROUTE_CUSTOMER = 'admin.reports.sales.customer';

    public const TAB_CATEGORY = 'category';

    public const TAB_PRODUCT = 'product';

    public const TAB_CUSTOMER = 'customer';

    public const PER_PAGE = 25;

    /**
     * Three years of orders take seconds to add up: the figures are kept this long, under a key
     * that changes with the orders (see OrderRepository::changeFingerprint()), and the screen says
     * when they were computed.
     */
    public const KEPT_SECONDS = 900;

    private const SCOPE_ALL = 'all';

    private const SCOPE_PAID = 'paid';

    private const SORT_DEFAULT = 'total';

    private const ROUTES = [
        self::TAB_CATEGORY => self::ROUTE_CATEGORY,
        self::TAB_PRODUCT => self::ROUTE_PRODUCT,
        self::TAB_CUSTOMER => self::ROUTE_CUSTOMER,
    ];

    private const EXPORT_REFS = [
        self::TAB_CATEGORY => 'thelia.export.sales_by_category',
        self::TAB_PRODUCT => 'thelia.export.sales_by_product',
        self::TAB_CUSTOMER => 'thelia.export.sales_by_customer',
    ];

    private const CUSTOMER_SORTS = ['name', 'orders', 'total', 'average', 'last_order'];

    public function __construct(
        private AdminSaleUnitScope $saleUnitScope,
        private CacheInterface $cache,
        private ClockInterface $clock,
        private DataTransferRepository $dataTransfers,
        private OrderRepository $orders,
        private PeriodOptions $periodOptions,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public static function routeOf(string $tab): string
    {
        return self::ROUTES[$tab];
    }

    /**
     * The column a customer table is sorted on: the one asked for when it is one of the columns.
     */
    public static function customerSort(?string $requested): string
    {
        return \in_array($requested, self::CUSTOMER_SORTS, true) ? (string) $requested : self::SORT_DEFAULT;
    }

    public function compute(string $tab, DateRange $range, bool $allOrders, string $sort, ?string $direction, int $page, string $locale, bool $refresh = false): SalesReport
    {
        $criteria = new \Thelia\Domain\Report\Sales\SalesReportCriteria(
            $range->from,
            $range->to,
            $allOrders ? \Thelia\Domain\Report\Sales\StatusScope::All : \Thelia\Domain\Report\Sales\StatusScope::Paid,
            $this->saleUnitScope->allowedSaleUnitIds(),
        );
        $previous = $criteria->previous();
        $descending = \in_array($direction, ['asc', 'desc'], true) ? 'desc' === $direction : 'name' !== $sort;

        $key = 'bo_sales_report_'.hash('xxh128', serialize([
            $tab, $criteria->from->format(\DATE_ATOM), $criteria->to->format(\DATE_ATOM), $criteria->scope->value,
            $criteria->saleUnitIds, $sort, $descending, $page, $locale, $this->orders->changeFingerprint(),
        ]));

        if ($refresh) {
            $this->cache->delete($key);
        }

        $figures = $this->cache->get($key, fn (ItemInterface $item): array => $this->figures($item, $tab, $criteria, $previous, $sort, $descending, $page, $locale));

        $current = $figures['current'];
        $before = $figures['before'];
        $categoryTree = [];
        $rows = [];
        $reconciliation = null;
        $pagination = null;

        if (self::TAB_CATEGORY === $tab) {
            $categoryTree = $this->tree($figures['result']);
            $reconciliation = [
                'linesTotal' => $current->linesTotal,
                'discounts' => $current->discounts,
                'postage' => $current->postage,
                'revenue' => $current->revenue,
            ];
        } elseif (self::TAB_PRODUCT === $tab) {
            $result = $figures['result'];
            $rows = array_map(static fn ($row): array => ['row' => $row, 'variation' => self::variation($row->revenue, $row->previousRevenue)], $result->rows);
            $pagination = $this->pagination($result, $tab, $range, $allOrders, $sort, $descending);
        } else {
            $result = $figures['result'];
            $rows = array_map(static fn ($row): array => ['row' => $row, 'variation' => self::variation($row->total, $row->previousTotal)], $result->rows);
            $pagination = $this->pagination($result, $tab, $range, $allOrders, $sort, $descending);
        }

        return new SalesReport(
            tab: $tab,
            range: $range,
            previousFrom: $previous->from,
            previousTo: $previous->to,
            allOrders: $allOrders,
            tabs: $this->tabs($tab, $range, $allOrders),
            periodOptions: $this->periodOptions->build($range, self::ROUTES[$tab], $this->keptParameters($allOrders, $tab, $sort, $descending)),
            scopeOptions: $this->scopeOptions($tab, $range, $allOrders, $sort, $descending),
            formUrl: $this->urls->generate(self::ROUTES[$tab]),
            formFields: ['scope' => $allOrders ? self::SCOPE_ALL : self::SCOPE_PAID],
            revenue: $current->revenue,
            previousRevenue: $before->revenue,
            revenueVariation: self::variation($current->revenue, $before->revenue),
            orderCount: $current->orderCount,
            previousOrderCount: $before->orderCount,
            orderVariation: self::variation($current->orderCount, $before->orderCount),
            categoryTree: $categoryTree,
            rows: $rows,
            reconciliation: $reconciliation,
            pagination: $pagination,
            sort: $sort,
            direction: $descending ? 'desc' : 'asc',
            sortUrls: self::TAB_CUSTOMER === $tab ? $this->sortUrls($range, $allOrders, $sort, $descending) : [],
            currencyCode: (string) \Thelia\Model\Currency::getDefaultCurrency()->getCode(),
            export: $allOrders ? null : $this->export($tab, $range, $locale),
            computedAt: $figures['computedAt'],
            refreshUrl: $this->urls->generate(self::ROUTES[$tab], $this->periodParameters($range) + $this->keptParameters($allOrders, $tab, $sort, $descending) + ($page > 1 ? ['page' => (string) $page] : []) + ['refresh' => '1']),
        );
    }

    /**
     * @return array{computedAt: \DateTimeImmutable, current: object, before: object, result: mixed}
     */
    private function figures(ItemInterface $item, string $tab, object $criteria, object $previous, string $sort, bool $descending, int $page, string $locale): array
    {
        $item->expiresAfter(self::KEPT_SECONDS);
        $calculator = $this->calculator();

        return [
            'computedAt' => $this->clock->now(),
            'current' => $calculator->reconciliation($criteria),
            'before' => $calculator->reconciliation($previous),
            'result' => match ($tab) {
                self::TAB_CATEGORY => $calculator->byCategory($criteria, $locale),
                self::TAB_PRODUCT => $calculator->byProduct($criteria, $locale, $page, self::PER_PAGE),
                default => $calculator->byCustomer($criteria, \Thelia\Domain\Report\Sales\CustomerSort::from($sort), $descending, $page, self::PER_PAGE),
            },
        ];
    }

    private function calculator(): object
    {
        return new \Thelia\Domain\Report\Sales\SalesReportCalculator();
    }

    /**
     * The rows of the calculator come in tree order, a parent then its children: they are nested
     * back for the screen to fold them.
     *
     * @param list<object> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function tree(array $rows): array
    {
        $nodes = [];
        $byCategory = [];
        foreach ($rows as $row) {
            $node = ['row' => $row, 'variation' => self::variation($row->revenue, $row->previousRevenue), 'children' => []];
            $nodes[] = $node;
            $byCategory[$row->categoryId ?? 0] = \count($nodes) - 1;
        }

        // Children are attached deepest first, so that a node is complete when its parent takes it.
        for ($index = \count($nodes) - 1; $index >= 0; --$index) {
            $parentId = $nodes[$index]['row']->parentId;
            if (null !== $parentId && isset($byCategory[$parentId]) && $byCategory[$parentId] < $index) {
                array_unshift($nodes[$byCategory[$parentId]]['children'], $nodes[$index]);
                $nodes[$index] = null;
            }
        }

        return array_values(array_filter($nodes));
    }

    /**
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function tabs(string $current, DateRange $range, bool $allOrders): array
    {
        $labels = [
            self::TAB_CATEGORY => $this->translator->trans('By category'),
            self::TAB_PRODUCT => $this->translator->trans('By product'),
            self::TAB_CUSTOMER => $this->translator->trans('By customer'),
        ];

        $tabs = [];
        foreach ($labels as $key => $label) {
            if (self::TAB_CUSTOMER === $key && !$this->canView(AdminResources::CUSTOMER)) {
                continue;
            }

            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'url' => $this->urls->generate(self::ROUTES[$key], $this->periodParameters($range) + ($allOrders ? ['scope' => self::SCOPE_ALL] : [])),
                'active' => $key === $current,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    private function scopeOptions(string $tab, DateRange $range, bool $allOrders, string $sort, bool $descending): array
    {
        $options = [];
        foreach ([self::SCOPE_PAID => 'Paid orders', self::SCOPE_ALL => 'All orders'] as $value => $label) {
            $parameters = $this->periodParameters($range) + $this->sortParameters($tab, $sort, $descending);
            if (self::SCOPE_ALL === $value) {
                $parameters['scope'] = self::SCOPE_ALL;
            }

            $options[] = [
                'value' => $value,
                'label' => $this->translator->trans($label),
                'active' => (self::SCOPE_ALL === $value) === $allOrders,
                'url' => $this->urls->generate(self::ROUTES[$tab], $parameters),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function sortUrls(DateRange $range, bool $allOrders, string $sort, bool $descending): array
    {
        $urls = [];
        foreach (self::CUSTOMER_SORTS as $column) {
            // The column already sorted on is reversed; another one opens on its natural direction.
            $nextDescending = $column === $sort ? !$descending : 'name' !== $column;
            $urls[$column] = $this->urls->generate(
                self::ROUTE_CUSTOMER,
                $this->periodParameters($range) + ($allOrders ? ['scope' => self::SCOPE_ALL] : []) + ['sort' => $column, 'dir' => $nextDescending ? 'desc' : 'asc'],
            );
        }

        return $urls;
    }

    /**
     * @param \Thelia\Domain\Report\Sales\SalesPage<object> $result
     *
     * @return array<string, mixed>
     */
    private function pagination(object $result, string $tab, DateRange $range, bool $allOrders, string $sort, bool $descending): array
    {
        return [
            'route' => self::ROUTES[$tab],
            'params' => $this->periodParameters($range) + ($allOrders ? ['scope' => self::SCOPE_ALL] : []) + $this->sortParameters($tab, $sort, $descending),
            'current' => $result->page,
            'last' => $result->pageCount(),
            'total' => $result->totalRows,
        ];
    }

    /**
     * The parameters a period pill keeps: the scope and the sort of the table, never the page.
     *
     * @return array<string, string>
     */
    private function keptParameters(bool $allOrders, string $tab, string $sort, bool $descending): array
    {
        return ($allOrders ? ['scope' => self::SCOPE_ALL] : []) + $this->sortParameters($tab, $sort, $descending);
    }

    /**
     * @return array<string, string>
     */
    private function sortParameters(string $tab, string $sort, bool $descending): array
    {
        return self::TAB_CUSTOMER === $tab ? ['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc'] : [];
    }

    /**
     * @return array<string, string>
     */
    private function periodParameters(DateRange $range): array
    {
        if (DateRange::PRESET_CUSTOM === $range->preset) {
            return ['period' => DateRange::PRESET_CUSTOM, 'from' => $range->from->format('Y-m-d'), 'to' => $range->to->format('Y-m-d')];
        }

        return ['period' => $range->preset];
    }

    /**
     * The export of the tab, over the exact days on screen. It counts the paid orders: it is not
     * offered beside the figures of every order.
     *
     * @return array<string, mixed>|null
     */
    private function export(string $tab, DateRange $range, string $locale): ?array
    {
        if (!$this->canView(AdminResources::EXPORT)) {
            return null;
        }

        $exportId = $this->dataTransfers->findExportIdByRef(self::EXPORT_REFS[$tab]);
        if (null === $exportId) {
            return null;
        }

        $languages = $this->dataTransfers->languages();
        $language = null;
        foreach ($languages as $candidate) {
            if ($candidate->getLocale() === $locale) {
                $language = $candidate;
                break;
            }
        }
        $language ??= $languages[0] ?? null;
        if (null === $language) {
            return null;
        }

        return [
            'url' => $this->urls->generate('export.process', ['id' => $exportId]),
            'from' => $range->from->format('Y-m-d'),
            'to' => $range->to->format('Y-m-d'),
            'languageId' => (int) $language->getId(),
        ];
    }

    private static function variation(float|int $current, float|int $previous): ?float
    {
        return 0.0 === (float) $previous ? null : ($current - $previous) / $previous * 100;
    }

    private function canView(string $resource): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [$resource], [], [AccessManager::VIEW]);
    }
}

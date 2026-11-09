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

namespace BackOfficeDefaultTwigBundle\DTO\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;

/**
 * What the "Reports > Sales" screen shows. The rows are the ones of the core calculator,
 * kept as they come (read by their public properties in the template) beside what the
 * screen derives from them: the variation against the previous period, the links.
 */
final readonly class SalesReport
{
    /**
     * @param list<array{key: string, label: string, url: string, active: bool}>   $tabs
     * @param list<array{value: string, label: string, active: bool, url: string}> $periodOptions
     * @param list<array{value: string, label: string, active: bool, url: string}> $scopeOptions
     * @param array<string, string>                                                $formFields     the parameters the period form keeps
     * @param list<array<string, mixed>>                                           $categoryTree   nodes: row, variation, children
     * @param list<array<string, mixed>>                                           $rows           products or customers: row, variation
     * @param array<string, string>                                                $sortUrls       by column, customers only
     * @param array<string, mixed>|null                                            $reconciliation
     * @param array<string, mixed>|null                                            $pagination
     * @param array<string, mixed>|null                                            $export
     * @param \DateTimeImmutable                                                   $computedAt     when the figures were computed: they are kept a few minutes
     */
    public function __construct(
        public string $tab,
        public DateRange $range,
        public \DateTimeImmutable $previousFrom,
        public \DateTimeImmutable $previousTo,
        public bool $allOrders,
        public array $tabs,
        public array $periodOptions,
        public array $scopeOptions,
        public string $formUrl,
        public array $formFields,
        public float $revenue,
        public float $previousRevenue,
        public ?float $revenueVariation,
        public int $orderCount,
        public int $previousOrderCount,
        public ?float $orderVariation,
        public array $categoryTree,
        public array $rows,
        public ?array $reconciliation,
        public ?array $pagination,
        public string $sort,
        public string $direction,
        public array $sortUrls,
        public string $currencyCode,
        public ?array $export,
        public \DateTimeImmutable $computedAt,
        public string $refreshUrl,
    ) {
    }
}

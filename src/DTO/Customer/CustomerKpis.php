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

namespace BackOfficeDefaultTwigBundle\DTO\Customer;

/**
 * What a customer is worth to the shop, on the orders it earned money from: the paid,
 * processing and sent statuses, the same ones the dashboard revenue adds up.
 */
final readonly class CustomerKpis
{
    public function __construct(
        public int $orderCount,
        public int $excludedOrderCount,
        public float $totalSpent,
        public ?float $averageBasket,
        public ?\DateTimeImmutable $firstOrderAt,
        public ?\DateTimeImmutable $lastOrderAt,
        public string $currencySymbol,
        public bool $mixesCurrencies,
    ) {
    }
}

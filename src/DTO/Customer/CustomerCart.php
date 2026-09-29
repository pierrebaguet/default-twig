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
 * A cart of the customer that never became an order. The amount excludes tax.
 */
final readonly class CustomerCart
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastActivityAt,
        public int $lineCount,
        public float $quantity,
        public float $amount,
        public string $currencySymbol,
    ) {
    }
}

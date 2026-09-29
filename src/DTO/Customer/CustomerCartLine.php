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
 * A line of the customer's current cart. `productUrl` is null when the product is gone.
 */
final readonly class CustomerCartLine
{
    public function __construct(
        public string $title,
        public string $reference,
        public float $quantity,
        public float $unitPrice,
        public float $lineTotal,
        public bool $isPromo,
        public bool $isOffered,
        public ?string $productUrl,
    ) {
    }
}

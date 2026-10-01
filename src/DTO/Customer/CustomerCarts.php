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
 * The carts section of the customer sheet.
 *
 * `current` is the cart the customer was filling in the last day, with its lines;
 * `abandoned` holds the most recent older carts that never became an order, and
 * `moreAbandoned` says whether older ones were left out of the list. Carts older than
 * `horizonDays` are not listed: the shop purges them, so their absence says nothing.
 */
final readonly class CustomerCarts
{
    /**
     * @param list<CustomerCartLine> $currentLines
     * @param list<CustomerCart>     $abandoned
     */
    public function __construct(
        public ?CustomerCart $current,
        public array $currentLines,
        public array $abandoned,
        public int $horizonDays,
        public bool $moreAbandoned,
    ) {
    }
}

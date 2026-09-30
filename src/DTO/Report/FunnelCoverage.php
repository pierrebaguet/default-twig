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
use Thelia\Domain\Cart\Service\CartPurgeHorizon;

/**
 * The purge deletes the carts without order past their retention while orders
 * are kept: before the purge horizon, orders would be compared to carts that no
 * longer exist, so the funnel starts on the horizon, to the second. The oldest
 * cart in base says nothing here: a cart that led to an order is never purged.
 */
final readonly class FunnelCoverage
{
    public function __construct(
        public \DateTimeImmutable $requestedFrom,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $truncated,
        public int $retentionDays,
    ) {
    }

    public static function resolve(DateRange $requested, CartPurgeHorizon $horizon, \DateTimeImmutable $now): self
    {
        $truncated = $horizon->mayHavePurged($requested->from, $now);

        return new self(
            requestedFrom: $requested->from,
            from: $truncated ? $horizon->earliestSurvivingCartDate($now) : $requested->from,
            to: $requested->to,
            truncated: $truncated,
            retentionDays: $horizon->retentionDays(),
        );
    }
}

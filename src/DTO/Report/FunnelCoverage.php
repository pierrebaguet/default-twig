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
 * Carts are purged on their creation date while orders are kept: a period that
 * reaches before the oldest cart would compare orders to carts that no longer
 * exist, so the funnel starts on the day of the oldest cart still in the database.
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

    public static function resolve(DateRange $requested, ?\DateTimeImmutable $oldestCart, int $retentionDays): self
    {
        $oldestCartDay = $oldestCart?->setTime(0, 0);
        $truncated = null !== $oldestCartDay && $oldestCartDay > $requested->from;

        return new self(
            requestedFrom: $requested->from,
            from: $truncated ? $oldestCartDay : $requested->from,
            to: $requested->to,
            truncated: $truncated,
            retentionDays: $retentionDays,
        );
    }
}

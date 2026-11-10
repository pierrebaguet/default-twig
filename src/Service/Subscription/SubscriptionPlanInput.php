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

namespace BackOfficeDefaultTwigBundle\Service\Subscription;

/**
 * What the plan form of the product sheet sends, read and checked.
 */
final readonly class SubscriptionPlanInput
{
    /**
     * @param array<string, string> $titles locale => title, the empty ones left out
     * @param array<int, string>    $prices currency id => price without taxes, the empty ones left out
     */
    public function __construct(
        public string $periodUnit,
        public int $periodCount,
        public int $trialDays,
        public ?int $installmentCount,
        public ?int $commitmentCount,
        public bool $pausable,
        public bool $visible,
        public array $titles,
        public array $prices,
    ) {
    }
}

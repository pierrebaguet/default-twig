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

use BackOfficeDefaultTwigBundle\Repository\SubscriptionRepository;

/**
 * The option lists of the filter bar, each read once per locale for the request.
 */
final class SubscriptionFilterCatalog
{
    /** @var array<string, list<array{id: int, label: string}>> */
    private array $plansByLocale = [];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function plans(string $locale): array
    {
        return $this->plansByLocale[$locale] ??= $this->subscriptions->findPlanOptions($locale);
    }
}

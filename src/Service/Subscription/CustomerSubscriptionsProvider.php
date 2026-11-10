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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The subscriptions section of the customer sheet: the most recent subscriptions of the
 * customer with their state and a link, and the link to the whole list filtered on them.
 * Nothing is read, and the section is left out, on a core without the subscriptions or for
 * an administrator who may not see them.
 */
final readonly class CustomerSubscriptionsProvider
{
    private const LIMIT = 10;

    public function __construct(
        private SubscriptionSupport $support,
        private AdminAccessChecker $access,
        private SubscriptionRepository $subscriptions,
        private SubscriptionListRowPresenter $rows,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function canView(): bool
    {
        return $this->support->isAvailable() && $this->access->canView(SubscriptionSupport::RESOURCE);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, list_url: string}
     */
    public function forCustomer(int $customerId, string $locale): array
    {
        if (!$this->canView()) {
            return ['rows' => [], 'total' => 0, 'list_url' => ''];
        }

        return [
            'rows' => $this->rows->presentAll($this->subscriptions->findByCustomer($customerId, $locale, self::LIMIT), $locale),
            'total' => $this->subscriptions->countByCustomer($customerId),
            'list_url' => $this->urls->generate(SubscriptionFilterPresenter::LIST_ROUTE, ['customer_id' => $customerId]),
        ];
    }
}

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

use Symfony\Component\HttpFoundation\Request;

/**
 * Single source of truth for the filters of the subscription list: parsing, URL
 * serialisation and removal of one filter. The repository turns them into SQL.
 *
 * The states are the values of the core enum as strings: the class does not exist on a core
 * without the subscriptions, and the list is never reached there.
 */
final readonly class SubscriptionFilters
{
    /** @var list<string> */
    public const STATUSES = ['trial', 'active', 'payment_failed', 'suspended', 'paused', 'cancelled', 'ended'];

    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_SORTS = ['id', 'ref', 'created_at', 'next_billing_at'];
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];

    public const KEY_STATUSES = 'statuses';
    public const KEY_SEARCH = 'q';
    public const KEY_PLAN = 'plan';
    public const KEY_CUSTOMER_ID = 'customer_id';
    public const KEY_NEXT_BILLING = 'next_billing';

    /**
     * @param list<string> $statuses
     */
    public function __construct(
        public array $statuses = [],
        public string $search = '',
        public ?int $customerId = null,
        public ?int $planId = null,
        public ?\DateTimeImmutable $nextBillingFrom = null,
        public ?\DateTimeImmutable $nextBillingTo = null,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $statuses = [];
        foreach ($query->all('status') as $status) {
            if (\is_string($status) && \in_array($status, self::STATUSES, true) && !\in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        $from = self::parseDate((string) $query->get('next_from', ''), false);
        $to = self::parseDate((string) $query->get('next_to', ''), true);

        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to->setTime(0, 0, 0), $from->setTime(23, 59, 59)];
        }

        $sort = (string) $query->get('order', self::DEFAULT_SORT);
        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        $customerId = (int) $query->get('customer_id', 0);
        $planId = (int) $query->get('plan', 0);

        return new self(
            statuses: $statuses,
            search: trim((string) $query->get('q', '')),
            customerId: $customerId > 0 ? $customerId : null,
            planId: $planId > 0 ? $planId : null,
            nextBillingFrom: $from,
            nextBillingTo: $to,
            sort: \in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: \in_array($direction, self::ALLOWED_DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION,
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->statuses
            && '' === $this->search
            && null === $this->customerId
            && null === $this->planId
            && null === $this->nextBillingFrom
            && null === $this->nextBillingTo;
    }

    /**
     * Omits the defaults so that the URLs stay short.
     *
     * @return array<string, scalar|list<string>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ([] !== $this->statuses) {
            $params['status'] = $this->statuses;
        }
        if ('' !== $this->search) {
            $params['q'] = $this->search;
        }
        if (null !== $this->customerId) {
            $params['customer_id'] = $this->customerId;
        }
        if (null !== $this->planId) {
            $params['plan'] = $this->planId;
        }
        if (null !== $this->nextBillingFrom) {
            $params['next_from'] = $this->nextBillingFrom->format('Y-m-d');
        }
        if (null !== $this->nextBillingTo) {
            $params['next_to'] = $this->nextBillingTo->format('Y-m-d');
        }
        if (self::DEFAULT_SORT !== $this->sort) {
            $params['order'] = $this->sort;
        }
        if (self::DEFAULT_DIRECTION !== $this->direction) {
            $params['direction'] = $this->direction;
        }

        return $params;
    }

    /**
     * @param list<string> $statuses
     */
    public function withStatuses(array $statuses): self
    {
        return $this->with(statuses: $statuses);
    }

    public function withoutFilter(string $key): self
    {
        return match ($key) {
            self::KEY_STATUSES => $this->with(statuses: []),
            self::KEY_SEARCH => $this->with(search: ''),
            self::KEY_CUSTOMER_ID => $this->with(customerId: null),
            self::KEY_PLAN => $this->with(planId: null),
            self::KEY_NEXT_BILLING => $this->with(nextBillingFrom: null, nextBillingTo: null),
            default => $this,
        };
    }

    /**
     * @param list<string>|null $statuses
     */
    private function with(?array $statuses = null, ?string $search = null, int|false|null $customerId = false, int|false|null $planId = false, \DateTimeImmutable|false|null $nextBillingFrom = false, \DateTimeImmutable|false|null $nextBillingTo = false): self
    {
        return new self(
            $statuses ?? $this->statuses,
            $search ?? $this->search,
            false === $customerId ? $this->customerId : $customerId,
            false === $planId ? $this->planId : $planId,
            false === $nextBillingFrom ? $this->nextBillingFrom : $nextBillingFrom,
            false === $nextBillingTo ? $this->nextBillingTo : $nextBillingTo,
            $this->sort,
            $this->direction,
        );
    }

    private static function parseDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $value = trim($value);

        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $date = new \DateTimeImmutable($value);

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
    }
}

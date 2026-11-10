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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the filter bar of the subscription list shows: the shortcut chips with their
 * counters, the state options, the chips of the filters in force with the link that removes
 * each, and the values the inputs start from.
 */
final readonly class SubscriptionFilterPresenter
{
    public const LIST_ROUTE = 'admin.subscription.list';

    /** The states a merchant looks at first, as shortcut chips. */
    private const SHORTCUT_STATUSES = ['active', 'payment_failed'];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private SubscriptionLabels $labels,
        private SubscriptionFilterCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, int> $counts the number of subscriptions per state
     *
     * @return array<string, mixed>
     */
    public function present(SubscriptionFilters $filters, array $counts, string $locale): array
    {
        $chips = [[
            'code' => 'all',
            'label' => $this->translator->trans('All subscriptions'),
            'count' => array_sum($counts),
            'active' => [] === $filters->statuses,
            'url' => $this->url($filters->withStatuses([])),
        ]];

        foreach (self::SHORTCUT_STATUSES as $status) {
            $chips[] = [
                'code' => $status,
                'label' => $this->labels->status($status),
                'count' => $counts[$status] ?? 0,
                'active' => [$status] === $filters->statuses,
                'color' => $this->labels->statusColor($status),
                'url' => $this->url($filters->withStatuses([$status])),
            ];
        }

        $statusOptions = [];
        foreach (SubscriptionFilters::STATUSES as $status) {
            $statusOptions[] = [
                'value' => $status,
                'label' => $this->labels->status($status),
                'color' => $this->labels->statusColor($status),
                'selected' => \in_array($status, $filters->statuses, true),
            ];
        }

        return [
            'quick_chips' => $chips,
            'status_options' => $statusOptions,
            'search_input' => $filters->search,
            'customer_id' => $filters->customerId,
            'plan_options' => $this->catalog->plans($locale),
            'selected_plan_id' => $filters->planId,
            'next_from_input' => $filters->nextBillingFrom?->format('Y-m-d') ?? '',
            'next_to_input' => $filters->nextBillingTo?->format('Y-m-d') ?? '',
            'active_chips' => $this->activeChips($filters, $locale),
            'clear_all_url' => $this->urls->generate(self::LIST_ROUTE),
            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
            'advanced_count' => (int) (null !== $filters->nextBillingFrom || null !== $filters->nextBillingTo) + (int) (null !== $filters->planId),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string, remove_url: string}>
     */
    private function activeChips(SubscriptionFilters $filters, string $locale): array
    {
        $chips = [];

        if ([] !== $filters->statuses) {
            $chips[] = $this->chip(
                $filters,
                SubscriptionFilters::KEY_STATUSES,
                $this->translator->trans('Status'),
                implode(', ', array_map($this->labels->status(...), $filters->statuses)),
            );
        }

        if ('' !== $filters->search) {
            $chips[] = $this->chip($filters, SubscriptionFilters::KEY_SEARCH, $this->translator->trans('Search'), $filters->search);
        }

        if (null !== $filters->customerId) {
            $chips[] = $this->chip($filters, SubscriptionFilters::KEY_CUSTOMER_ID, $this->translator->trans('Customer'), '#'.$filters->customerId);
        }

        if (null !== $filters->planId) {
            $label = '#'.$filters->planId;
            foreach ($this->catalog->plans($locale) as $option) {
                if ($option['id'] === $filters->planId) {
                    $label = $option['label'];
                }
            }
            $chips[] = $this->chip($filters, SubscriptionFilters::KEY_PLAN, $this->translator->trans('Plan'), $label);
        }

        if (null !== $filters->nextBillingFrom || null !== $filters->nextBillingTo) {
            $from = null === $filters->nextBillingFrom ? '…' : $this->labels->date($filters->nextBillingFrom->format('Y-m-d H:i:s'), $locale);
            $to = null === $filters->nextBillingTo ? '…' : $this->labels->date($filters->nextBillingTo->format('Y-m-d H:i:s'), $locale);
            $chips[] = $this->chip($filters, SubscriptionFilters::KEY_NEXT_BILLING, $this->translator->trans('Next billing'), $from.' → '.$to);
        }

        return $chips;
    }

    /**
     * @return array{key: string, label: string, value: string, remove_url: string}
     */
    private function chip(SubscriptionFilters $filters, string $key, string $label, string $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'remove_url' => $this->url($filters->withoutFilter($key))];
    }

    private function url(SubscriptionFilters $filters): string
    {
        return $this->urls->generate(self::LIST_ROUTE, $filters->toQueryParams());
    }
}

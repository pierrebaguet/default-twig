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

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps the rows the repository reads to the shape of the DataTable of the list and of the
 * customer sheet: the formatting of the state, the amount and the dates lives here, not in
 * a template.
 */
final readonly class SubscriptionListRowPresenter
{
    public const DETAIL_ROUTE = 'admin.subscription.view';
    private const CUSTOMER_ROUTE = 'admin.customer.update.view';
    private const PRODUCT_ROUTE = 'admin.products.update';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private MoneyFormatter $money,
        private SubscriptionLabels $labels,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $subscriptions the rows of SubscriptionRepository
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $subscriptions, string $locale): array
    {
        return array_map(fn (array $subscription): array => $this->present($subscription, $locale), $subscriptions);
    }

    /**
     * @param array<string, mixed> $subscription
     *
     * @return array<string, mixed>
     */
    public function present(array $subscription, string $locale): array
    {
        $id = (int) $subscription['id'];
        $status = (string) $subscription['status'];
        $detailUrl = $this->urls->generate(self::DETAIL_ROUTE, ['subscription_id' => $id]);

        return [
            'id' => $id,
            'ref' => (string) $subscription['ref'],
            'ref_html' => \sprintf(
                '<a href="%s" class="link-primary text-decoration-none fw-medium" data-testid="subscription-ref-link">%s</a>',
                $this->escape($detailUrl),
                $this->escape((string) $subscription['ref']),
            ),
            'customer_html' => $this->customerHtml($subscription),
            'product_html' => $this->productHtml($subscription),
            'plan' => $this->labels->period((string) $subscription['period_unit'], (int) $subscription['period_count']),
            'amount' => $this->amount($subscription, $locale),
            'status_code' => $status,
            'status' => $this->labels->status($status),
            'status_color' => $this->labels->statusColor($status),
            'next_billing' => $this->labels->date($subscription['next_billing_at'] ?? null, $locale),
            'created_at' => $this->labels->date($subscription['created_at'] ?? null, $locale),
            '_actions' => [
                new RowAction('view', $this->translator->trans('View'), $detailUrl),
            ],
        ];
    }

    /**
     * The price of one installment: the unit price times the quantity, in the currency the
     * subscription was taken in.
     *
     * @param array<string, mixed> $subscription
     */
    public function amount(array $subscription, string $locale): string
    {
        $total = (float) $subscription['unit_price'] * max(1, (int) $subscription['quantity']);

        return $this->money->format($total, $locale, (string) $subscription['currency_code']);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function customerHtml(array $subscription): string
    {
        return \sprintf(
            '<div class="bo-order-customer"><a href="%s" class="fw-semibold text-decoration-none">%s %s</a><div class="text-muted small text-truncate">%s</div></div>',
            $this->escape($this->urls->generate(self::CUSTOMER_ROUTE, ['customer_id' => (int) $subscription['customer_id']])),
            $this->escape((string) $subscription['firstname']),
            $this->escape((string) $subscription['lastname']),
            $this->escape((string) $subscription['email']),
        );
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function productHtml(array $subscription): string
    {
        $title = (string) ($subscription['product_title'] ?? '');

        if ('' === $title) {
            return '<span class="text-muted fst-italic">'.$this->escape($this->translator->trans('Product deleted')).'</span>';
        }

        $plan = (string) ($subscription['plan_title'] ?? '');
        $planHtml = '' === $plan ? '' : '<div class="text-muted small text-truncate">'.$this->escape($plan).'</div>';

        return \sprintf(
            '<a href="%s" class="text-decoration-none">%s</a>%s',
            $this->escape($this->urls->generate(self::PRODUCT_ROUTE, ['product_id' => (int) $subscription['product_id']])),
            $this->escape($title),
            $planHtml,
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5);
    }
}

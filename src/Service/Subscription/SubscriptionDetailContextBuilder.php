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
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything the detail screen of a subscription shows: the values frozen on the
 * subscription (the plan it was taken on may have changed or gone since), the customer, the
 * product, the method as a label, the dates, the history, one page of the installments and
 * the gestures the state allows to this administrator.
 *
 * The payment method is only ever read as its label: the token never leaves the model.
 */
final readonly class SubscriptionDetailContextBuilder
{
    public const INSTALLMENTS_PER_PAGE = 12;

    private const ORDER_ROUTE = 'admin.order.update.view';

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionLabels $labels,
        private SubscriptionActions $actions,
        private AdminAccessChecker $access,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private MoneyFormatter $money,
    ) {
    }

    /**
     * @param array<string, mixed> $summary the row of SubscriptionRepository::findSummary()
     *
     * @return array<string, mixed>
     */
    public function build(array $summary, string $locale, int $installmentsPage): array
    {
        $id = (int) $summary['id'];
        $status = (string) $summary['status'];
        $total = $this->subscriptions->countInstallments($id);
        $lastPage = max(1, (int) ceil($total / self::INSTALLMENTS_PER_PAGE));
        $page = max(1, min($installmentsPage, $lastPage));
        $canUpdate = $this->access->canUpdate(SubscriptionSupport::RESOURCE);

        $offered = $canUpdate ? $this->actions->allowedFor($status, 1 === (int) $summary['pausable']) : [];

        return [
            'subscription' => $this->frozenValues($summary, $locale),
            'actions' => $offered,
            'installments' => $this->installmentRows($this->subscriptions->findInstallmentsPage($id, $page, self::INSTALLMENTS_PER_PAGE), $locale),
            'installments_total' => $total,
            'installments_page' => $page,
            'installments_last_page' => $lastPage,
            'history' => $this->historyRows($id, $locale),
            'list_url' => $this->urls->generate(SubscriptionFilterPresenter::LIST_ROUTE),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private function frozenValues(array $summary, string $locale): array
    {
        $status = (string) $summary['status'];
        $currency = (string) $summary['currency_code'];
        $quantity = max(1, (int) $summary['quantity']);
        $installmentCount = $summary['installment_count'] ?? null;
        $commitmentCount = $summary['commitment_count'] ?? null;
        $billed = (int) $summary['installments_billed'];

        return [
            'id' => (int) $summary['id'],
            'ref' => (string) $summary['ref'],
            'status' => $this->labels->status($status),
            'status_code' => $status,
            'status_color' => $this->labels->statusColor($status),
            'customer_name' => trim((string) $summary['firstname'].' '.(string) $summary['lastname']),
            'customer_email' => (string) $summary['email'],
            'customer_url' => $this->urls->generate('admin.customer.update.view', ['customer_id' => (int) $summary['customer_id']]),
            'product_title' => (string) ($summary['product_title'] ?? ''),
            'product_url' => null === ($summary['product_id'] ?? null) ? null : $this->urls->generate('admin.products.update', ['product_id' => (int) $summary['product_id'], 'current_tab' => 'subscription-plans']),
            'plan_title' => (string) ($summary['plan_title'] ?? ''),
            'period' => $this->labels->period((string) $summary['period_unit'], (int) $summary['period_count']),
            'quantity' => $quantity,
            'unit_price' => $this->money->format((float) $summary['unit_price'], $locale, $currency),
            'installment_price' => $this->money->format((float) $summary['unit_price'] * $quantity, $locale, $currency),
            'pausable' => 1 === (int) $summary['pausable'],
            'installment_count' => null === $installmentCount ? null : (int) $installmentCount,
            'commitment_count' => null === $commitmentCount ? null : (int) $commitmentCount,
            'installments_billed' => $billed,
            'failure_count' => (int) $summary['failure_count'],
            'payment_method_label' => $summary['payment_method_label'] ?? null,
            'payment_module' => (string) ($summary['payment_module_title'] ?? ''),
            'initial_order_ref' => $summary['initial_order_ref'] ?? null,
            'initial_order_url' => null === ($summary['initial_order_id'] ?? null) ? null : $this->urls->generate(self::ORDER_ROUTE, ['order_id' => (int) $summary['initial_order_id']]),
            'created_at' => $this->labels->date($summary['created_at'] ?? null, $locale, true),
            'current_period_start' => $this->labels->date($summary['current_period_start'] ?? null, $locale),
            'current_period_end' => $this->labels->date($summary['current_period_end'] ?? null, $locale),
            'next_billing_at' => $this->labels->date($summary['next_billing_at'] ?? null, $locale),
            'next_retry_at' => null === ($summary['next_retry_at'] ?? null) ? null : $this->labels->date($summary['next_retry_at'], $locale, true),
            'cancel_at' => null === ($summary['cancel_at'] ?? null) ? null : $this->labels->date($summary['cancel_at'], $locale),
            'paused_at' => null === ($summary['paused_at'] ?? null) ? null : $this->labels->date($summary['paused_at'], $locale),
        ];
    }

    /**
     * @param list<array{number: int, state: string, attempt_count: int, last_error: ?string, order_id: ?int, order_ref: ?string, created_at: string}> $installments
     *
     * @return list<array<string, mixed>>
     */
    private function installmentRows(array $installments, string $locale): array
    {
        $rows = [];

        foreach ($installments as $installment) {
            $orderUrl = null === $installment['order_id'] ? null : $this->urls->generate(self::ORDER_ROUTE, ['order_id' => $installment['order_id']]);

            $rows[] = [
                'id' => $installment['number'],
                'number' => '#'.$installment['number'],
                'date' => $this->labels->date($installment['created_at'], $locale),
                'state_code' => $installment['state'],
                'state' => $this->labels->installmentState($installment['state']),
                'state_color' => $this->labels->installmentColor($installment['state']),
                'attempts' => (string) $installment['attempt_count'],
                'order_html' => null === $orderUrl
                    ? '<span class="text-muted">-</span>'
                    : \sprintf('<a href="%s" class="link-primary text-decoration-none" data-testid="subscription-installment-order-link">%s</a>', htmlspecialchars($orderUrl, \ENT_QUOTES | \ENT_HTML5), htmlspecialchars((string) $installment['order_ref'], \ENT_QUOTES | \ENT_HTML5)),
                'last_error' => $installment['last_error'] ?? '',
                '_actions' => null === $orderUrl ? [] : [new RowAction('view', $this->translator->trans('View the order'), $orderUrl)],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRows(int $subscriptionId, string $locale): array
    {
        $rows = [];

        foreach ($this->subscriptions->findHistory($subscriptionId) as $entry) {
            $from = $entry['from_status'];
            $rows[] = [
                'date' => $this->labels->date($entry['created_at'], $locale, true),
                'change' => null === $from || $from === $entry['to_status']
                    ? $this->labels->status($entry['to_status'])
                    : $this->labels->status($from).' → '.$this->labels->status($entry['to_status']),
                'to_status_color' => $this->labels->statusColor($entry['to_status']),
                'actor' => $this->labels->actor($entry['actor_type'], $entry['actor_login'], $entry['actor_id']),
                'note' => $entry['note'] ?? '',
            ];
        }

        return $rows;
    }
}

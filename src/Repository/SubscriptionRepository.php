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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilters;
use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\Propel;

/**
 * The reads of the subscription screens, in SQL: the list joins the customer, the currency,
 * the product and the plan titles in the one query of the page, and the figures are
 * aggregates, never sums over loaded models.
 *
 * Plain SQL on purpose: the Propel models of the subscriptions exist only on a core that
 * carries them, and this class is loaded on any core.
 */
readonly class SubscriptionRepository
{
    private const SORT_COLUMNS = [
        'id' => 's.id',
        'ref' => 's.ref',
        'created_at' => 's.created_at',
        'next_billing_at' => 's.next_billing_at',
    ];

    /** The states that bring revenue the shop counts on: a failed charge is still expected. */
    private const RECURRING_STATUSES = ['active', 'payment_failed'];

    private const SUMMARY_SELECT = 's.id, s.ref, s.status, s.quantity, s.unit_price, s.period_unit, s.period_count, '
        .'s.installments_billed, s.installment_count, s.commitment_count, s.pausable, s.failure_count, '
        .'s.current_period_start, s.current_period_end, s.next_billing_at, s.next_retry_at, s.cancel_at, s.paused_at, s.created_at, '
        .'s.customer_id, c.firstname, c.lastname, c.email, cur.code AS currency_code, '
        .'p.id AS product_id, p.ref AS product_ref, COALESCE(NULLIF(pi.title, \'\'), p.ref) AS product_title, '
        .'COALESCE(NULLIF(spi.title, \'\'), \'\') AS plan_title';

    private const SUMMARY_FROM = 'FROM `subscription` s '
        .'INNER JOIN `customer` c ON c.id = s.customer_id '
        .'INNER JOIN `currency` cur ON cur.id = s.currency_id '
        .'LEFT JOIN `product_sale_elements` pse ON pse.id = s.product_sale_elements_id '
        .'LEFT JOIN `product` p ON p.id = pse.product_id '
        .'LEFT JOIN `product_i18n` pi ON pi.id = p.id AND pi.locale = :locale_product '
        .'LEFT JOIN `subscription_plan_i18n` spi ON spi.id = s.subscription_plan_id AND spi.locale = :locale_plan ';

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, lastPage: int}
     */
    public function findPaginated(SubscriptionFilters $filters, string $locale, int $page, int $perPage): array
    {
        [$where, $parameters] = $this->where($filters);

        $count = Propel::getConnection()->prepare('SELECT COUNT(*) FROM `subscription` s INNER JOIN `customer` c ON c.id = s.customer_id '.$where);
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        if (0 === $total) {
            return ['rows' => [], 'total' => 0, 'lastPage' => 1];
        }

        $column = self::SORT_COLUMNS[$filters->sort] ?? self::SORT_COLUMNS[SubscriptionFilters::DEFAULT_SORT];
        $direction = 'asc' === $filters->direction ? 'ASC' : 'DESC';
        // A subscription with nothing left to bill has no next date: it goes last either way.
        $nullsLast = 's.next_billing_at' === $column ? '(s.next_billing_at IS NULL) ASC, ' : '';

        $statement = Propel::getConnection()->prepare(
            'SELECT '.self::SUMMARY_SELECT.' '.self::SUMMARY_FROM.$where
            .' ORDER BY '.$nullsLast.$column.' '.$direction.', s.id '.$direction.' LIMIT :limit OFFSET :offset',
        );
        $this->bind($statement, $parameters + $this->locales($locale));
        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $this->typed($statement->fetchAll(\PDO::FETCH_ASSOC)), 'total' => $total, 'lastPage' => $lastPage];
    }

    /**
     * The number of subscriptions in each state, in one query.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = [];

        foreach (Propel::getConnection()->query('SELECT status, COUNT(*) FROM `subscription` GROUP BY status')->fetchAll(\PDO::FETCH_NUM) as [$status, $count]) {
            $counts[(string) $status] = (int) $count;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSummary(int $subscriptionId, string $locale): ?array
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT '.self::SUMMARY_SELECT.', s.billing_anchor_day, s.initial_order_id, io.ref AS initial_order_ref, '
            .'s.customer_payment_method_id, cpm.label AS payment_method_label, '
            .'s.payment_module_id, COALESCE(NULLIF(mi.title, \'\'), m.code) AS payment_module_title, s.subscription_plan_id '
            .self::SUMMARY_FROM
            .'LEFT JOIN `order` io ON io.id = s.initial_order_id '
            .'LEFT JOIN `customer_payment_method` cpm ON cpm.id = s.customer_payment_method_id '
            .'LEFT JOIN `module` m ON m.id = s.payment_module_id '
            .'LEFT JOIN `module_i18n` mi ON mi.id = m.id AND mi.locale = :locale_module '
            .'WHERE s.id = :id',
        );
        $this->bind($statement, $this->locales($locale, true));
        $statement->bindValue('id', $subscriptionId, \PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : $this->typed([$row])[0];
    }

    /**
     * Newest first. The login of the administrator is read in the same query; a deleted
     * administrator leaves its identifier.
     *
     * @return list<array{from_status: ?string, to_status: string, actor_type: string, actor_id: ?int, actor_login: ?string, note: ?string, created_at: string}>
     */
    public function findHistory(int $subscriptionId, int $limit = 50): array
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT h.from_status, h.to_status, h.actor_type, h.actor_id, a.login AS actor_login, h.note, h.created_at '
            .'FROM `subscription_history` h LEFT JOIN `admin` a ON h.actor_type = \'admin\' AND a.id = h.actor_id '
            .'WHERE h.subscription_id = :id ORDER BY h.id DESC LIMIT :limit',
        );
        $statement->bindValue('id', $subscriptionId, \PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'from_status' => null === $row['from_status'] ? null : (string) $row['from_status'],
                'to_status' => (string) $row['to_status'],
                'actor_type' => (string) $row['actor_type'],
                'actor_id' => null === $row['actor_id'] ? null : (int) $row['actor_id'],
                'actor_login' => null === $row['actor_login'] ? null : (string) $row['actor_login'],
                'note' => null === $row['note'] ? null : (string) $row['note'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $rows;
    }

    /**
     * The plans the list can be filtered on, with the title of their product, in one query.
     *
     * @return list<array{id: int, label: string}>
     */
    public function findPlanOptions(string $locale): array
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT sp.id, COALESCE(NULLIF(spi.title, \'\'), CONCAT(\'#\', sp.id)) AS plan_title, COALESCE(NULLIF(pi.title, \'\'), p.ref) AS product_title '
            .'FROM `subscription_plan` sp INNER JOIN `product` p ON p.id = sp.product_id '
            .'LEFT JOIN `subscription_plan_i18n` spi ON spi.id = sp.id AND spi.locale = :locale_plan '
            .'LEFT JOIN `product_i18n` pi ON pi.id = p.id AND pi.locale = :locale_product '
            .'ORDER BY product_title ASC, sp.position ASC, sp.id ASC',
        );
        $this->bind($statement, ['locale_plan' => $locale, 'locale_product' => $locale]);
        $statement->execute();

        $options = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $options[] = ['id' => (int) $row['id'], 'label' => $row['plan_title'].' ('.$row['product_title'].')'];
        }

        return $options;
    }

    public function countInstallments(int $subscriptionId): int
    {
        $statement = Propel::getConnection()->prepare('SELECT COUNT(*) FROM `subscription_order` WHERE subscription_id = :id');
        $statement->bindValue('id', $subscriptionId, \PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * One page of the installments of a subscription, read by the identifier of the
     * subscription: an order is reached only through the installment that carries it.
     *
     * @return list<array{number: int, state: string, attempt_count: int, last_error: ?string, order_id: ?int, order_ref: ?string, created_at: string}>
     */
    public function findInstallmentsPage(int $subscriptionId, int $page, int $perPage): array
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT so.installment_number, so.state, so.attempt_count, so.last_error, so.order_id, o.ref AS order_ref, so.created_at '
            .'FROM `subscription_order` so LEFT JOIN `order` o ON o.id = so.order_id '
            .'WHERE so.subscription_id = :id ORDER BY so.installment_number DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('id', $subscriptionId, \PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', (max(1, $page) - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'number' => (int) $row['installment_number'],
                'state' => (string) $row['state'],
                'attempt_count' => (int) $row['attempt_count'],
                'last_error' => null === $row['last_error'] ? null : (string) $row['last_error'],
                'order_id' => null === $row['order_id'] ? null : (int) $row['order_id'],
                'order_ref' => null === $row['order_ref'] ? null : (string) $row['order_ref'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $rows;
    }

    /**
     * The most recent subscriptions of a customer, for the customer sheet.
     *
     * @return list<array<string, mixed>>
     */
    public function findByCustomer(int $customerId, string $locale, int $limit): array
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT '.self::SUMMARY_SELECT.' '.self::SUMMARY_FROM
            .'WHERE s.customer_id = :customer ORDER BY s.created_at DESC, s.id DESC LIMIT :limit',
        );
        $this->bind($statement, $this->locales($locale));
        $statement->bindValue('customer', $customerId, \PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $this->typed($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByCustomer(int $customerId): int
    {
        $statement = Propel::getConnection()->prepare('SELECT COUNT(*) FROM `subscription` WHERE customer_id = :id');
        $statement->bindValue('id', $customerId, \PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * What the active subscriptions bring in a month, in the default currency, by one
     * aggregate: the unit price times the quantity, converted at the rate of the currency of
     * the subscription, spread over the months an installment pays for. A week counts for
     * 12/52 of a month. The states that count are the active one and the one waiting for a
     * charge to be paid again; a trial brings nothing yet, a paused or suspended one nothing
     * any more.
     */
    public function monthlyRecurringRevenue(): float
    {
        $statuses = implode(', ', array_map(static fn (string $status): string => "'".$status."'", self::RECURRING_STATUSES));

        $value = Propel::getConnection()->query(
            'SELECT COALESCE(SUM(s.unit_price * s.quantity / NULLIF(cur.rate, 0) * '.self::monthlyFactorSql('s.period_unit', 's.period_count').'), 0) '
            .'FROM `subscription` s INNER JOIN `currency` cur ON cur.id = s.currency_id '
            .'WHERE s.status IN ('.$statuses.')',
        )->fetchColumn();

        return round((float) $value, 2);
    }

    /**
     * The share of a month one installment stands for, in SQL: 1 / (months per period) for a
     * month, a quarter or a year, 12/52 of a month per week, each divided by the period count.
     * Exposed so that a test can hold the normalisation to the same expression the aggregate runs.
     */
    public static function monthlyFactorSql(string $unitColumn, string $countColumn): string
    {
        $count = 'GREATEST('.$countColumn.', 1)';

        return 'CASE '.$unitColumn
            .' WHEN \'week\' THEN 52.0 / (12 * '.$count.')'
            .' WHEN \'month\' THEN 1.0 / '.$count
            .' WHEN \'quarter\' THEN 1.0 / (3 * '.$count.')'
            .' WHEN \'year\' THEN 1.0 / (12 * '.$count.')'
            .' ELSE 0 END';
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function where(SubscriptionFilters $filters): array
    {
        $conditions = [];
        $parameters = [];

        if ([] !== $filters->statuses) {
            $names = [];
            foreach ($filters->statuses as $index => $status) {
                $names[] = ':status'.$index;
                $parameters['status'.$index] = $status;
            }
            $conditions[] = 's.status IN ('.implode(', ', $names).')';
        }

        if (null !== $filters->customerId) {
            $conditions[] = 's.customer_id = :customer_id';
            $parameters['customer_id'] = $filters->customerId;
        }

        if (null !== $filters->planId) {
            $conditions[] = 's.subscription_plan_id = :plan_id';
            $parameters['plan_id'] = $filters->planId;
        }

        if ('' !== $filters->search) {
            // One group: the OR must not escape the AND of the other filters.
            $conditions[] = '(s.ref LIKE :q_ref OR c.lastname LIKE :q_lastname OR c.firstname LIKE :q_firstname OR c.email LIKE :q_email)';
            $like = '%'.$this->escapeLike($filters->search).'%';
            $parameters += ['q_ref' => $like, 'q_lastname' => $like, 'q_firstname' => $like, 'q_email' => $like];
        }

        if (null !== $filters->nextBillingFrom) {
            $conditions[] = 's.next_billing_at >= :next_from';
            $parameters['next_from'] = $filters->nextBillingFrom->format('Y-m-d H:i:s');
        }

        if (null !== $filters->nextBillingTo) {
            $conditions[] = 's.next_billing_at <= :next_to';
            $parameters['next_to'] = $filters->nextBillingTo->format('Y-m-d H:i:s');
        }

        return [[] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions), $parameters];
    }

    /**
     * One name per use: a statement that is not emulated refuses a name used twice.
     *
     * @return array<string, string>
     */
    private function locales(string $locale, bool $withModule = false): array
    {
        return ['locale_product' => $locale, 'locale_plan' => $locale] + ($withModule ? ['locale_module' => $locale] : []);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function bind(StatementInterface $statement, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function typed(array $rows): array
    {
        $integers = ['id', 'quantity', 'period_count', 'installments_billed', 'installment_count', 'commitment_count', 'pausable', 'failure_count', 'customer_id', 'product_id', 'billing_anchor_day', 'initial_order_id', 'customer_payment_method_id', 'payment_module_id', 'subscription_plan_id'];

        foreach ($rows as &$row) {
            foreach ($integers as $key) {
                if (\array_key_exists($key, $row) && null !== $row[$key]) {
                    $row[$key] = (int) $row[$key];
                }
            }

            $row['unit_price'] = (float) $row['unit_price'];
        }
        unset($row);

        return $rows;
    }
}

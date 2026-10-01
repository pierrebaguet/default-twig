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

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilters;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\CustomerTitle;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Centralised Propel queries for the customer back-office screens. Mirrors
 * the OrderRepository layout: filtered pagination, adaptive slider bounds,
 * eager-batch lookups for the row presenter (N+1 avoidance).
 */
final readonly class CustomerRepository
{
    public const SORT_FIELDS = [
        'lastname' => 'lastname',
        'firstname' => 'firstname',
        'email' => 'email',
        'ref' => 'ref',
        'created_at' => 'createdAt',
    ];

    public const COMPUTED_SORT_FIELDS = [
        'total_spent' => 'total_spent_sort',
        'order_count' => 'order_count_sort',
    ];

    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    public function countAll(): int
    {
        return (int) CustomerQuery::create()->count();
    }

    public function countNew(DateRange $range): int
    {
        return (int) CustomerQuery::create()
            ->filterByCreatedAt($range->fromSql(), Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($range->toSql(), Criteria::LESS_EQUAL)
            ->count();
    }

    /**
     * @return array{rows: ObjectCollection<int, Customer>, total: int, lastPage: int}
     */
    public function findPaginated(CustomerFilters $filters, int $page, int $perPage): array
    {
        // Read once for the three queries below: the status list is deliberately not memoised.
        $revenueStatusIds = $this->orders->revenueStatusIds();

        $countQuery = $this->buildFilteredQuery($filters, $revenueStatusIds);
        $total = (int) $countQuery->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $rowsQuery = $this->buildFilteredQuery($filters, $revenueStatusIds);
        $direction = $filters->direction === 'asc' ? Criteria::ASC : Criteria::DESC;

        if (\array_key_exists($filters->sort, self::COMPUTED_SORT_FIELDS)) {
            $alias = self::COMPUTED_SORT_FIELDS[$filters->sort];
            $expression = $filters->sort === 'total_spent'
                ? CustomerFilters::totalSpentSqlExpression('customer.id', $revenueStatusIds)
                : CustomerFilters::orderCountSqlExpression('customer.id', $revenueStatusIds);
            $rowsQuery->withColumn($expression, $alias);
            $rowsQuery->orderBy($alias, $direction);
        } else {
            $column = self::SORT_FIELDS[$filters->sort] ?? self::SORT_FIELDS[CustomerFilters::DEFAULT_SORT];
            $rowsQuery->orderBy('customer.'.$column, $direction);
        }

        /** @var ObjectCollection<int, Customer> $rows */
        $rows = $rowsQuery
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    /**
     * Total spent per customer for a given list of customer IDs, on the revenue statuses
     * only (see {@see CustomerFilters::totalSpentSqlExpression()}). Returns a map indexed
     * by customer_id so the row presenter can hit it in O(1).
     *
     * @param list<int> $customerIds
     *
     * @return array<int, float>
     */
    public function findTotalSpentByCustomer(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT customer_id, COALESCE(SUM(computed), 0) AS total
            FROM (
                SELECT customer_id, '.OrderFilters::totalAmountSqlExpression().' AS computed
                FROM `order`
                WHERE customer_id IN ('.$placeholders.')
                  AND status_id IN ('.$this->orders->revenueStatusIdListSql().')
            ) AS sub
            GROUP BY customer_id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $totals = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $totals[(int) $row['customer_id']] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * Order count per customer for the given list, on the revenue statuses only (see
     * {@see CustomerFilters::orderCountSqlExpression()}).
     *
     * @param list<int> $customerIds
     *
     * @return array<int, int>
     */
    public function findOrderCounts(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT customer_id, COUNT(*) AS total
            FROM `order`
            WHERE customer_id IN ('.$placeholders.')
              AND status_id IN ('.$this->orders->revenueStatusIdListSql().')
            GROUP BY customer_id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $counts = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $counts[(int) $row['customer_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Last order created_at per customer.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, ?\DateTimeImmutable>
     */
    public function findLastOrderDateByCustomer(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT customer_id, MAX(created_at) AS last_at
            FROM `order`
            WHERE customer_id IN ('.$placeholders.')
            GROUP BY customer_id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $dates = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $customerId = (int) $row['customer_id'];
            $raw = $row['last_at'];
            $dates[$customerId] = $raw ? new \DateTimeImmutable((string) $raw) : null;
        }

        return $dates;
    }

    /**
     * Newsletter subscription flag per customer (joined by email).
     *
     * @param list<int> $customerIds
     *
     * @return array<int, bool>
     */
    public function findNewsletterFlags(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT c.id, EXISTS (
                SELECT 1 FROM newsletter n
                WHERE n.email = c.email AND n.unsubscribed = 0
            ) AS subscribed
            FROM customer c
            WHERE c.id IN ('.$placeholders.')';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $flags = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $flags[(int) $row['id']] = (bool) $row['subscribed'];
        }

        return $flags;
    }

    /**
     * Primary phone (default address fallback to first address) per customer.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, string>
     */
    public function findPrimaryPhones(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT customer_id,
                COALESCE(
                    MAX(CASE WHEN is_default = 1 THEN NULLIF(phone, "") END),
                    MAX(CASE WHEN is_default = 1 THEN NULLIF(cellphone, "") END),
                    MAX(NULLIF(phone, "")),
                    MAX(NULLIF(cellphone, ""))
                ) AS phone
            FROM address
            WHERE customer_id IN ('.$placeholders.')
            GROUP BY customer_id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $phones = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $phones[(int) $row['customer_id']] = (string) ($row['phone'] ?? '');
        }

        return $phones;
    }

    /**
     * Primary delivery country per customer (default address first).
     *
     * @param list<int> $customerIds
     *
     * @return array<int, int>
     */
    public function findPrimaryCountryIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($customerIds), '?'));
        $sql = 'SELECT customer_id,
                COALESCE(MAX(CASE WHEN is_default = 1 THEN country_id END), MAX(country_id)) AS country_id
            FROM address
            WHERE customer_id IN ('.$placeholders.')
            GROUP BY customer_id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($customerIds);

        $countries = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $countries[(int) $row['customer_id']] = (int) $row['country_id'];
        }

        return $countries;
    }

    /**
     * Country IDs referenced by at least one customer address, used to keep
     * the country dropdown trimmed to what actually has customers.
     *
     * @return list<int>
     */
    public function findReferencedCountryIds(): array
    {
        $sql = 'SELECT DISTINCT country_id FROM address WHERE country_id IS NOT NULL';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();

        $ids = [];
        while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            $id = (int) $row[0];
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array{min: float, max: float, step: int}
     */
    public function getTotalSpentBounds(): array
    {
        $sql = 'SELECT MAX(total) AS max_val FROM (
            SELECT customer_id, COALESCE(SUM(computed), 0) AS total
            FROM (
                SELECT customer_id, '.OrderFilters::totalAmountSqlExpression().' AS computed
                FROM `order`
                WHERE status_id IN ('.$this->orders->revenueStatusIdListSql().')
            ) AS per_order
            GROUP BY customer_id
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (float) ($row['max_val'] ?? 0);

        $niceMax = self::roundUpToNice($rawMax);
        $step = $niceMax >= 5000 ? 100 : ($niceMax >= 1000 ? 50 : 10);

        return ['min' => 0.0, 'max' => (float) $niceMax, 'step' => $step];
    }

    /**
     * @return array{min: int, max: int, step: int}
     */
    public function getOrderCountBounds(): array
    {
        $sql = 'SELECT MAX(cnt) AS max_val FROM (
            SELECT COUNT(*) AS cnt FROM `order`
            WHERE status_id IN ('.$this->orders->revenueStatusIdListSql().')
            GROUP BY customer_id
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (int) ($row['max_val'] ?? 0);
        $niceMax = max(5, (int) (ceil(max(1, $rawMax) / 5) * 5));

        return ['min' => 0, 'max' => $niceMax, 'step' => 1];
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function findLangs(): array
    {
        $items = [];
        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            \assert($lang instanceof Lang);
            $items[] = [
                'id' => (int) $lang->getId(),
                'title' => (string) ($lang->getTitle() ?: $lang->getCode()),
                'code' => (string) $lang->getCode(),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function findTitlesLocalized(string $locale): array
    {
        $items = [];
        foreach (CustomerTitleQuery::create()->orderByPosition()->find() as $title) {
            \assert($title instanceof CustomerTitle);
            $title->setLocale($locale);
            $items[] = [
                'id' => (int) $title->getId(),
                'title' => (string) ($title->getShort() ?: $title->getLong() ?: ('#'.$title->getId())),
            ];
        }

        return $items;
    }

    /**
     * @param list<int> $revenueStatusIds
     */
    private function buildFilteredQuery(CustomerFilters $filters, array $revenueStatusIds): CustomerQuery
    {
        $query = CustomerQuery::create();
        $filters->applyTo($query, $revenueStatusIds);

        return $query;
    }

    private static function roundUpToNice(float $value): int
    {
        if ($value <= 100) {
            return 100;
        }
        if ($value <= 500) {
            return 500;
        }
        if ($value <= 1000) {
            return 1000;
        }
        if ($value <= 5000) {
            return (int) (ceil($value / 500) * 500);
        }

        return (int) (ceil($value / 1000) * 1000);
    }

    /**
     * @param list<int> $ids
     *
     * @return ObjectCollection<int, Customer>
     */
    public function findByIds(array $ids): ObjectCollection
    {
        if ($ids === []) {
            /** @var ObjectCollection<int, Customer> $empty */
            $empty = new ObjectCollection();
            $empty->setModel(Customer::class);

            return $empty;
        }

        /** @var ObjectCollection<int, Customer> $rows */
        $rows = CustomerQuery::create()->filterById($ids, Criteria::IN)->find();

        return $rows;
    }

    /**
     * @return ObjectCollection<int, Customer>
     */
    public function findLatest(int $limit): ObjectCollection
    {
        /** @var ObjectCollection<int, Customer> $rows */
        $rows = CustomerQuery::create()
            ->orderByCreatedAt(Criteria::DESC)
            ->limit($limit)
            ->find();

        return $rows;
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Customer $current): array
    {
        $previous = CustomerQuery::create()
            ->filterById($current->getId(), Criteria::LESS_THAN)
            ->orderById(Criteria::DESC)
            ->findOne();
        $next = CustomerQuery::create()
            ->filterById($current->getId(), Criteria::GREATER_THAN)
            ->orderById(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }
}

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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;

/**
 * Single source of truth for the order list filters: parsing, URL serialisation
 * and SQL application live here so the controller stays thin.
 */
final readonly class OrderFilters
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_TODAY = 'today';
    public const PERIOD_WEEK = 'week';
    public const ALLOWED_PERIODS = [self::PERIOD_ALL, self::PERIOD_TODAY, self::PERIOD_WEEK];

    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];
    public const ALLOWED_SORTS = ['id', 'ref', 'created_at', 'amount'];

    public const TRISTATE_WITH = 'with';
    public const TRISTATE_WITHOUT = 'without';

    public const KEY_STATUS_IDS = 'status_ids';
    public const KEY_CREATED_RANGE = 'created_range';
    public const KEY_AMOUNT_RANGE = 'amount_range';
    public const KEY_PAYMENT_MODULE_IDS = 'payment_module_ids';
    public const KEY_DELIVERY_MODULE_IDS = 'delivery_module_ids';
    public const KEY_COUNTRY_ID = 'country_id';
    public const KEY_ITEMS_RANGE = 'items_range';
    public const KEY_COUPON = 'coupon';
    public const KEY_TRACKING = 'tracking';
    public const KEY_PREORDER = 'preorder';
    public const KEY_SEARCH = 'search';
    public const KEY_PERIOD = 'period';
    public const KEY_CUSTOMER_ID = 'customer_id';
    public const KEY_SALE_UNIT_IDS = 'sale_unit_ids';

    /** @var list<array{code: string, label: string, icon: string}> */
    public const QUICK_CHIPS = [
        ['code' => self::PERIOD_ALL, 'label' => 'All orders', 'icon' => 'bi-list-ul'],
        ['code' => self::PERIOD_TODAY, 'label' => 'Today', 'icon' => 'bi-sun'],
        ['code' => self::PERIOD_WEEK, 'label' => 'Last 7 days', 'icon' => 'bi-calendar-week'],
    ];

    /**
     * @param list<int> $statusIds
     * @param list<int> $paymentModuleIds
     * @param list<int> $deliveryModuleIds
     * @param list<int> $saleUnitIds
     */
    public function __construct(
        public array $statusIds = [],
        public ?\DateTimeImmutable $createdFrom = null,
        public ?\DateTimeImmutable $createdTo = null,
        public ?float $minAmount = null,
        public ?float $maxAmount = null,
        public array $paymentModuleIds = [],
        public array $deliveryModuleIds = [],
        public ?int $countryId = null,
        public ?int $customerId = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $hasCoupon = null,
        public ?bool $hasTracking = null,
        public string $search = '',
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public string $period = self::PERIOD_ALL,
        public array $saleUnitIds = [],
        public ?bool $hasPreorder = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $statusIds = self::parseIntArray($query->all('status_ids'));
        if ($statusIds === []) {
            $legacy = (int) $query->get('status_id', 0);
            if ($legacy > 0) {
                $statusIds = [$legacy];
            }
        }

        $period = (string) $query->get('period', self::PERIOD_ALL);
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        $createdFrom = self::parseDate((string) $query->get('created_from', ''), endOfDay: false);
        $createdTo = self::parseDate((string) $query->get('created_to', ''), endOfDay: true);

        if ($createdFrom === null && $createdTo === null) {
            [$createdFrom, $createdTo] = self::periodToRange($period);
        } else {
            // Explicit range wins over a quick-period chip to avoid stale UI state.
            $period = self::PERIOD_ALL;
        }

        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            [$createdFrom, $createdTo] = [$createdTo->setTime(0, 0, 0), $createdFrom->setTime(23, 59, 59)];
        }

        $minAmount = self::parseFloat((string) $query->get('min_amount', ''));
        $maxAmount = self::parseFloat((string) $query->get('max_amount', ''));
        if ($minAmount !== null && $maxAmount !== null && $minAmount > $maxAmount) {
            [$minAmount, $maxAmount] = [$maxAmount, $minAmount];
        }

        $minItems = self::parsePositiveInt((string) $query->get('min_items', ''));
        $maxItems = self::parsePositiveInt((string) $query->get('max_items', ''));
        if ($minItems !== null && $maxItems !== null && $minItems > $maxItems) {
            [$minItems, $maxItems] = [$maxItems, $minItems];
        }

        $sort = (string) $query->get('order', self::DEFAULT_SORT);
        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        return new self(
            statusIds: $statusIds,
            createdFrom: $createdFrom,
            createdTo: $createdTo,
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            paymentModuleIds: self::parseIntArray($query->all('payment_module_ids')),
            deliveryModuleIds: self::parseIntArray($query->all('delivery_module_ids')),
            countryId: self::parsePositiveInt((string) $query->get('country_id', '')),
            customerId: self::parsePositiveInt((string) $query->get('customer_id', '')),
            minItems: $minItems,
            maxItems: $maxItems,
            hasCoupon: self::parseTriState((string) $query->get('coupon', '')),
            hasTracking: self::parseTriState((string) $query->get('tracking', '')),
            hasPreorder: self::parseTriState((string) $query->get('preorder', '')),
            search: trim((string) $query->get('q', '')),
            sort: $sort,
            direction: $direction,
            period: $period,
            saleUnitIds: self::parseIntArray($query->all('sale_unit_ids')),
        );
    }

    public function isEmpty(): bool
    {
        return $this->statusIds === []
            && $this->createdFrom === null
            && $this->createdTo === null
            && $this->minAmount === null
            && $this->maxAmount === null
            && $this->paymentModuleIds === []
            && $this->deliveryModuleIds === []
            && $this->countryId === null
            && $this->customerId === null
            && $this->minItems === null
            && $this->maxItems === null
            && $this->hasCoupon === null
            && $this->hasTracking === null
            && $this->hasPreorder === null
            && $this->saleUnitIds === [];
    }

    /**
     * Omits defaults so URLs stay short and bookmarkable.
     *
     * @return array<string, scalar|list<int>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->statusIds !== []) {
            $params['status_ids'] = $this->statusIds;
        }
        if ($this->createdFrom !== null) {
            $params['created_from'] = $this->createdFrom->format('Y-m-d');
        }
        if ($this->createdTo !== null) {
            $params['created_to'] = $this->createdTo->format('Y-m-d');
        }
        if ($this->minAmount !== null) {
            $params['min_amount'] = $this->minAmount;
        }
        if ($this->maxAmount !== null) {
            $params['max_amount'] = $this->maxAmount;
        }
        if ($this->paymentModuleIds !== []) {
            $params['payment_module_ids'] = $this->paymentModuleIds;
        }
        if ($this->deliveryModuleIds !== []) {
            $params['delivery_module_ids'] = $this->deliveryModuleIds;
        }
        if ($this->countryId !== null) {
            $params['country_id'] = $this->countryId;
        }
        if ($this->customerId !== null) {
            $params['customer_id'] = $this->customerId;
        }
        if ($this->minItems !== null) {
            $params['min_items'] = $this->minItems;
        }
        if ($this->maxItems !== null) {
            $params['max_items'] = $this->maxItems;
        }
        if ($this->hasCoupon !== null) {
            $params['coupon'] = $this->hasCoupon ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->hasTracking !== null) {
            $params['tracking'] = $this->hasTracking ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->hasPreorder !== null) {
            $params['preorder'] = $this->hasPreorder ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->saleUnitIds !== []) {
            $params['sale_unit_ids'] = $this->saleUnitIds;
        }
        if ($this->search !== '') {
            $params['q'] = $this->search;
        }
        if ($this->sort !== self::DEFAULT_SORT) {
            $params['order'] = $this->sort;
        }
        if ($this->direction !== self::DEFAULT_DIRECTION) {
            $params['direction'] = $this->direction;
        }
        if ($this->period !== self::PERIOD_ALL) {
            $params['period'] = $this->period;
        }

        return $params;
    }

    public function cloneForPeriodShortcut(string $period): self
    {
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        [$from, $to] = self::periodToRange($period);

        return $this->cloneWith([
            'period' => $period,
            'createdFrom' => $from,
            'createdTo' => $to,
        ]);
    }

    public function withoutFilter(string $key): self
    {
        $overrides = match ($key) {
            self::KEY_STATUS_IDS => ['statusIds' => []],
            self::KEY_CREATED_RANGE, self::KEY_PERIOD => [
                'createdFrom' => null,
                'createdTo' => null,
                'period' => self::PERIOD_ALL,
            ],
            self::KEY_AMOUNT_RANGE => ['minAmount' => null, 'maxAmount' => null],
            self::KEY_PAYMENT_MODULE_IDS => ['paymentModuleIds' => []],
            self::KEY_DELIVERY_MODULE_IDS => ['deliveryModuleIds' => []],
            self::KEY_COUNTRY_ID => ['countryId' => null],
            self::KEY_CUSTOMER_ID => ['customerId' => null],
            self::KEY_ITEMS_RANGE => ['minItems' => null, 'maxItems' => null],
            self::KEY_COUPON => ['hasCoupon' => null],
            self::KEY_TRACKING => ['hasTracking' => null],
            self::KEY_PREORDER => ['hasPreorder' => null],
            self::KEY_SEARCH => ['search' => ''],
            self::KEY_SALE_UNIT_IDS => ['saleUnitIds' => []],
            default => [],
        };

        return $this->cloneWith($overrides);
    }

    public function applyTo(OrderQuery $query): OrderQuery
    {
        if ($this->statusIds !== []) {
            $query->filterByStatusId($this->statusIds, Criteria::IN);
        }

        if ($this->createdFrom !== null) {
            $query->filterByCreatedAt($this->createdFrom->format('Y-m-d H:i:s'), Criteria::GREATER_EQUAL);
        }
        if ($this->createdTo !== null) {
            $query->filterByCreatedAt($this->createdTo->format('Y-m-d H:i:s'), Criteria::LESS_EQUAL);
        }

        if ($this->paymentModuleIds !== []) {
            $query->filterByPaymentModuleId($this->paymentModuleIds, Criteria::IN);
        }
        if ($this->deliveryModuleIds !== []) {
            $query->filterByDeliveryModuleId($this->deliveryModuleIds, Criteria::IN);
        }

        if ($this->countryId !== null) {
            $query->useOrderAddressRelatedByDeliveryOrderAddressIdQuery()
                ->filterByCountryId($this->countryId)
                ->endUse();
        }

        if ($this->customerId !== null) {
            $query->filterByCustomerId($this->customerId);
        }

        if ($this->saleUnitIds !== []) {
            $query->filterBySaleUnitId($this->saleUnitIds, Criteria::IN);
        }

        $this->applyCouponFilter($query);
        $this->applyTrackingFilter($query);
        $this->applyPreorderFilter($query);
        $this->applyItemsRange($query);
        $this->applyAmountRange($query);
        $this->applySearch($query);

        return $query;
    }

    private function applySearch(OrderQuery $query): void
    {
        if ($this->search === '') {
            return;
        }

        $needle = '%'.$this->search.'%';

        // condition+combine groups the OR cluster as a single AND clause, otherwise
        // chaining _or() bleeds into the surrounding filters and turns the whole
        // WHERE into an OR (status_ids OR search instead of status_ids AND search).
        $query
            ->condition('search_ref', OrderTableMap::COL_REF.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->condition(
                'search_customer',
                'EXISTS (SELECT 1 FROM customer c WHERE c.id = '.OrderTableMap::COL_CUSTOMER_ID
                    .' AND CONCAT_WS(\' \', c.firstname, c.lastname, c.email) LIKE ?)',
                $needle,
                \PDO::PARAM_STR,
            )
            ->combine(['search_ref', 'search_customer'], 'OR');
    }

    private function applyCouponFilter(OrderQuery $query): void
    {
        if ($this->hasCoupon === null) {
            return;
        }

        $clause = $this->hasCoupon ? 'EXISTS' : 'NOT EXISTS';
        $query->where(
            $clause.' (SELECT 1 FROM order_coupon oc WHERE oc.order_id = '.OrderTableMap::COL_ID.')'
        );
    }

    /**
     * The same question as the badge of the list: an order is a preorder when at least one of
     * its lines was sold as one.
     */
    private function applyPreorderFilter(OrderQuery $query): void
    {
        if ($this->hasPreorder === null) {
            return;
        }

        $clause = $this->hasPreorder ? 'EXISTS' : 'NOT EXISTS';
        $query->where(
            $clause.' (SELECT 1 FROM order_product opp WHERE opp.order_id = '.OrderTableMap::COL_ID.' AND opp.is_preorder = 1)'
        );
    }

    private function applyTrackingFilter(OrderQuery $query): void
    {
        if ($this->hasTracking === null) {
            return;
        }

        if ($this->hasTracking) {
            $query->where(
                OrderTableMap::COL_DELIVERY_REF.' IS NOT NULL AND '.OrderTableMap::COL_DELIVERY_REF." <> ''"
            );

            return;
        }

        $query->where(
            '('.OrderTableMap::COL_DELIVERY_REF.' IS NULL OR '.OrderTableMap::COL_DELIVERY_REF." = '')"
        );
    }

    private function applyItemsRange(OrderQuery $query): void
    {
        if ($this->minItems === null && $this->maxItems === null) {
            return;
        }

        $itemsSql = '(SELECT COUNT(*) FROM order_product op WHERE op.order_id = '.OrderTableMap::COL_ID.')';

        if ($this->minItems !== null) {
            $query->where($itemsSql.' >= ?', $this->minItems, \PDO::PARAM_INT);
        }
        if ($this->maxItems !== null) {
            $query->where($itemsSql.' <= ?', $this->maxItems, \PDO::PARAM_INT);
        }
    }

    private function applyAmountRange(OrderQuery $query): void
    {
        if ($this->minAmount === null && $this->maxAmount === null) {
            return;
        }

        $totalSql = self::totalAmountSqlExpression();

        if ($this->minAmount !== null) {
            $query->where($totalSql.' >= ?', $this->minAmount, \PDO::PARAM_STR);
        }
        if ($this->maxAmount !== null) {
            $query->where($totalSql.' <= ?', $this->maxAmount, \PDO::PARAM_STR);
        }
    }

    /**
     * Mirrors Order::getTotalAmount() in pure SQL (taxed line totals + postage - discount,
     * promo-aware). Lets us filter by amount without hydrating any model. Exposed
     * publicly so the Repository can reuse the very same formula when computing
     * adaptive slider bounds.
     *
     * Which rounding rule applies is a per-order question — see OrderRoundingRule —
     * so the expression carries the answer as a CASE on the order id: orders below
     * one of the two pivots keep the rule they were invoiced with, and the rest
     * follow the rule the shop runs today. A shop that never switched has no pivot
     * to honour, so it gets a single formula, the historical one.
     */
    public static function totalAmountSqlExpression(): string
    {
        // GREATEST mirrors the clamp in Order::getTotalAmount(): a discount larger
        // than the items it applies to brings the order down to zero, never below,
        // and postage is added after the clamp.
        return '(
            GREATEST('.self::taxedItemsTotalSqlExpression().' - '.OrderTableMap::COL_DISCOUNT.', 0)
            + '.OrderTableMap::COL_POSTAGE.'
        )';
    }

    /**
     * The taxed total of the order lines, the figure Order::getTotalAmount() reads
     * back as total_taxed_price before it takes the discount off and adds the
     * postage. Exposed so the order list can read it for a whole page of orders in
     * one query and finish the sum the way the model finishes it, to the cent.
     *
     * Which rounding rule applies is a per-order question — see OrderRoundingRule —
     * so the expression carries the answer as a CASE on the order id: orders below
     * one of the two pivots keep the rule they were invoiced with, and the rest
     * follow the rule the shop runs today. A shop that never switched has no pivot
     * to honour, so it gets a single formula, the historical one.
     *
     * An order with no line sums to NULL, which COALESCE turns into the zero the
     * model reads it as.
     */
    public static function taxedItemsTotalSqlExpression(): string
    {
        $textPromoPricePivot = (int) ConfigQuery::read('last_text_promo_price_order_id', 0);

        // An order placed while order_product.promo_price was a text keeps the total it was
        // invoiced with (Order::promoPriceOperand()): the whole total is read the other way
        // for it, the choice is not made on the unit price, whose type an IF would turn.
        // A core that knows no such pivot reads 0 here, and the expression is unchanged.
        if ($textPromoPricePivot <= 0) {
            return 'COALESCE('.self::itemsTotalByRoundingRuleSqlExpression(false).', 0)';
        }

        return 'COALESCE(CASE WHEN '.OrderTableMap::COL_ID.' <= '.$textPromoPricePivot
            .' THEN '.self::itemsTotalByRoundingRuleSqlExpression(true)
            .' ELSE '.self::itemsTotalByRoundingRuleSqlExpression(false).' END, 0)';
    }

    private static function itemsTotalByRoundingRuleSqlExpression(bool $textPromoPrice): string
    {
        $legacyPivot = OrderRoundingRule::legacyPivot();
        $sumOfRoundingsPivot = OrderRoundingRule::sumOfRoundingsPivot();
        $roundsLineTotals = OrderRoundingRule::RoundingOfSums === OrderRoundingRule::shopRule();

        $frozenBranches = [];

        if ($legacyPivot > 0) {
            $frozenBranches[] = 'WHEN '.OrderTableMap::COL_ID.' <= '.$legacyPivot
                .' THEN '.self::legacyItemsTotalSqlExpression($textPromoPrice);
        }

        if ($roundsLineTotals && $sumOfRoundingsPivot > 0) {
            $frozenBranches[] = 'WHEN '.OrderTableMap::COL_ID.' <= '.$sumOfRoundingsPivot
                .' THEN '.self::itemsTotalSqlExpression(false, $textPromoPrice);
        }

        $currentRule = self::itemsTotalSqlExpression($roundsLineTotals, $textPromoPrice);

        return [] === $frozenBranches
            ? $currentRule
            : 'CASE '.implode(' ', $frozenBranches).' ELSE '.$currentRule.' END';
    }

    /**
     * Totals the order lines the way Order::buildTotalAmountQuery() does for a single
     * order: rounding the unit amounts before multiplying by the quantity is the
     * historical rule, rounding the line total instead is what a shop selling by
     * weight or by volume needs.
     */
    private static function itemsTotalSqlExpression(bool $roundLineTotals, bool $textPromoPrice): string
    {
        $unitPrice = 'IF(op.was_in_promo = 1, '.self::promoPriceOperand($textPromoPrice).', op.price)';
        $unitTax = 'IF(op.was_in_promo = 1, opt.promo_amount, opt.amount)';

        if (!$roundLineTotals) {
            $unitPrice = 'ROUND('.$unitPrice.', 2)';
            $unitTax = 'ROUND('.$unitTax.', 2)';
        }

        $lineTotal = 'op.quantity * ('.$unitPrice.' + (
            SELECT COALESCE(SUM('.$unitTax.'), 0)
            FROM order_product_tax opt
            WHERE opt.order_product_id = op.id
        ))';

        if ($roundLineTotals) {
            $lineTotal = 'ROUND('.$lineTotal.', 2)';
        }

        return '(
            SELECT SUM('.$lineTotal.')
            FROM order_product op
            WHERE op.order_id = '.OrderTableMap::COL_ID.'
        )';
    }

    /**
     * Orders placed before Thelia 2.4 were totalled without any rounding at all, and
     * Order::getTotalAmountLegacy() still reads them that way: untaxed total and tax
     * summed apart, then added. The shape matters as much as the absence of ROUND —
     * summing the two apart and summing them per line land on different sides of a
     * half-cent tie.
     */
    private static function legacyItemsTotalSqlExpression(bool $textPromoPrice): string
    {
        return '(
            (
                SELECT SUM(op.quantity * IF(op.was_in_promo = 1, '.self::promoPriceOperand($textPromoPrice).', op.price))
                FROM order_product op
                WHERE op.order_id = '.OrderTableMap::COL_ID.'
            )
            + (
                SELECT COALESCE(SUM(op.quantity * IF(op.was_in_promo = 1, opt.promo_amount, opt.amount)), 0)
                FROM order_product op
                INNER JOIN order_product_tax opt ON opt.order_product_id = op.id
                WHERE op.order_id = '.OrderTableMap::COL_ID.'
            )
        )';
    }

    /**
     * The promotional price as Order::promoPriceOperand() reads it: a text for an order placed
     * while the column was one.
     */
    private static function promoPriceOperand(bool $textPromoPrice): string
    {
        return $textPromoPrice ? 'COALESCE(CAST(op.promo_price AS CHAR), \'\')' : 'op.promo_price';
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private static function periodToRange(string $period): array
    {
        return match ($period) {
            self::PERIOD_TODAY => [
                new \DateTimeImmutable('today 00:00:00'),
                new \DateTimeImmutable('today 23:59:59'),
            ],
            self::PERIOD_WEEK => [
                new \DateTimeImmutable('-6 days 00:00:00'),
                new \DateTimeImmutable('today 23:59:59'),
            ],
            default => [null, null],
        };
    }

    /**
     * @param array<int|string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            statusIds: \array_key_exists('statusIds', $overrides) ? $overrides['statusIds'] : $this->statusIds,
            createdFrom: \array_key_exists('createdFrom', $overrides) ? $overrides['createdFrom'] : $this->createdFrom,
            createdTo: \array_key_exists('createdTo', $overrides) ? $overrides['createdTo'] : $this->createdTo,
            minAmount: \array_key_exists('minAmount', $overrides) ? $overrides['minAmount'] : $this->minAmount,
            maxAmount: \array_key_exists('maxAmount', $overrides) ? $overrides['maxAmount'] : $this->maxAmount,
            paymentModuleIds: \array_key_exists('paymentModuleIds', $overrides) ? $overrides['paymentModuleIds'] : $this->paymentModuleIds,
            deliveryModuleIds: \array_key_exists('deliveryModuleIds', $overrides) ? $overrides['deliveryModuleIds'] : $this->deliveryModuleIds,
            countryId: \array_key_exists('countryId', $overrides) ? $overrides['countryId'] : $this->countryId,
            customerId: \array_key_exists('customerId', $overrides) ? $overrides['customerId'] : $this->customerId,
            minItems: \array_key_exists('minItems', $overrides) ? $overrides['minItems'] : $this->minItems,
            maxItems: \array_key_exists('maxItems', $overrides) ? $overrides['maxItems'] : $this->maxItems,
            hasCoupon: \array_key_exists('hasCoupon', $overrides) ? $overrides['hasCoupon'] : $this->hasCoupon,
            hasTracking: \array_key_exists('hasTracking', $overrides) ? $overrides['hasTracking'] : $this->hasTracking,
            search: \array_key_exists('search', $overrides) ? $overrides['search'] : $this->search,
            sort: \array_key_exists('sort', $overrides) ? $overrides['sort'] : $this->sort,
            direction: \array_key_exists('direction', $overrides) ? $overrides['direction'] : $this->direction,
            period: \array_key_exists('period', $overrides) ? $overrides['period'] : $this->period,
            saleUnitIds: \array_key_exists('saleUnitIds', $overrides) ? $overrides['saleUnitIds'] : $this->saleUnitIds,
            hasPreorder: \array_key_exists('hasPreorder', $overrides) ? $overrides['hasPreorder'] : $this->hasPreorder,
        );
    }

    /**
     * @return list<int>
     */
    private static function parseIntArray(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $raw) {
            $i = (int) $raw;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }

        return array_values($out);
    }

    private static function parseDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay
            ? $date->setTime(23, 59, 59)
            : $date->setTime(0, 0, 0);
    }

    private static function parseFloat(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $trimmed);
        if (!is_numeric($normalized)) {
            return null;
        }

        $float = (float) $normalized;

        return $float >= 0 ? $float : null;
    }

    private static function parsePositiveInt(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        $int = (int) $trimmed;

        return $int > 0 ? $int : null;
    }

    private static function parseTriState(string $value): ?bool
    {
        return match ($value) {
            self::TRISTATE_WITH => true,
            self::TRISTATE_WITHOUT => false,
            default => null,
        };
    }
}

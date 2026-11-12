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

use BackOfficeDefaultTwigBundle\Service\Customer\CustomerPhoneSearch;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\Search\SearchGroup;
use BackOfficeDefaultTwigBundle\Service\Search\SearchGroupResult;
use BackOfficeDefaultTwigBundle\Service\Search\SearchTerm;
use BackOfficeDefaultTwigBundle\Service\Search\SqlParameters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class SearchRepository
{
    public function __construct(
        private OrderAccess $orderAccess,
    ) {
    }

    /** The rows shown per group, and per page of a group shown on its own. */
    public const DISPLAY_LIMIT = 25;

    /** Score of a row whose identifier or title is the term itself. */
    private const EXACT = 0;

    /** Score of a row whose identifier or title starts with the term; any other match scores 2. */
    private const PREFIX = 1;

    private const MAX_COMBINATIONS_PER_PRODUCT = 25;

    /**
     * The combinations an order can be given a line for: those of the visible products whose
     * title or reference contains the term, which is what the order editor of the core accepts.
     *
     * @return list<array{pse_id: int, product_ref: string, title: string, pse_ref: string, combination: string, stock: string}>
     */
    public function findSellableCombinations(string $term, string $locale, int $limit = 20): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $pattern = '%'.addcslashes($term, '%_\\').'%';

        $titleIds = ProductI18nQuery::create()
            ->filterByLocale($locale)
            ->filterByTitle($pattern, Criteria::LIKE)
            ->select(['Id'])
            ->find()
            ->toArray();

        $products = ProductQuery::create()
            ->filterByVisible(1)
            ->filterByIsBundle(0)
            ->condition('byTitle', 'product.id IN ?', $titleIds ?: [0])
            ->condition('byRef', 'product.ref LIKE ?', $pattern)
            ->where(['byTitle', 'byRef'], Criteria::LOGICAL_OR)
            ->limit($limit)
            ->find();

        $titles = [];
        foreach ($products as $product) {
            $product->setLocale($locale);
            $titles[(int) $product->getId()] = ['ref' => (string) $product->getRef(), 'title' => (string) $product->getTitle()];
        }

        if ([] === $titles) {
            return [];
        }

        $elements = ProductSaleElementsQuery::create()
            ->filterByProductId(array_keys($titles), Criteria::IN)
            ->orderByProductId()
            ->orderByIsDefault(Criteria::DESC)
            ->orderById()
            ->find()
            ->getArrayCopy();

        $labels = $this->combinationLabels(array_map(static fn ($pse): int => (int) $pse->getId(), $elements), $locale);

        $rows = [];
        $perProduct = [];
        foreach ($elements as $pse) {
            $productId = (int) $pse->getProductId();

            if (($perProduct[$productId] = ($perProduct[$productId] ?? 0) + 1) > self::MAX_COMBINATIONS_PER_PRODUCT) {
                continue;
            }

            $rows[] = [
                'pse_id' => (int) $pse->getId(),
                'product_ref' => $titles[$productId]['ref'],
                'title' => $titles[$productId]['title'],
                'pse_ref' => (string) $pse->getRef(),
                'combination' => $labels[(int) $pse->getId()] ?? '',
                'stock' => rtrim(rtrim(number_format((float) $pse->getQuantity(), 6, '.', ''), '0'), '.') ?: '0',
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $productSaleElementsIds
     *
     * @return array<int, string>
     */
    private function combinationLabels(array $productSaleElementsIds, string $locale): array
    {
        if ([] === $productSaleElementsIds) {
            return [];
        }

        $combinations = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($productSaleElementsIds, Criteria::IN)
            ->find();

        $valueIds = [];
        foreach ($combinations as $combination) {
            $valueIds[] = (int) $combination->getAttributeAvId();
        }

        $titles = [];
        if ([] !== $valueIds) {
            foreach (AttributeAvQuery::create()->filterById($valueIds, Criteria::IN)->find() as $value) {
                $value->setLocale($locale);
                $titles[(int) $value->getId()] = (string) $value->getTitle();
            }
        }

        $labels = [];
        foreach ($combinations as $combination) {
            $labels[(int) $combination->getProductSaleElementsId()][] = $titles[(int) $combination->getAttributeAvId()] ?? '';
        }

        return array_map(static fn (array $parts): string => implode(' / ', array_filter($parts)), $labels);
    }

    /**
     * One page of a group, the most relevant rows first: an identifier or a title equal to the
     * term, then one starting with it, then one holding it. The relevance is computed by the
     * database before the limit, so a truncated group keeps its best rows. The rows are
     * counted only when they do not fit in one page.
     *
     * Titles are read in $locale, and in $fallbackLocale when they are missing or empty there;
     * a title in any language is searched.
     */
    public function find(SearchGroup $group, SearchTerm $term, string $locale, string $fallbackLocale, int $offset = 0): SearchGroupResult
    {
        $offset = max(0, $offset);

        return match ($group) {
            SearchGroup::Products => $this->findProducts($term, $locale, $fallbackLocale, $offset),
            SearchGroup::Combinations => $this->findCombinations($term, $locale, $fallbackLocale, $offset),
            SearchGroup::Categories => $this->findTranslated('category', $term, $locale, $fallbackLocale, $offset),
            SearchGroup::Folders => $this->findTranslated('folder', $term, $locale, $fallbackLocale, $offset),
            SearchGroup::Contents => $this->findTranslated('content', $term, $locale, $fallbackLocale, $offset),
            SearchGroup::Brands => $this->findTranslated('brand', $term, $locale, $fallbackLocale, $offset),
            SearchGroup::Sales => $this->findTranslated('sale', $term, $locale, $fallbackLocale, $offset),
            SearchGroup::Customers => $this->findCustomers($term, $offset),
            SearchGroup::Orders => $this->findOrders($term, $offset),
            SearchGroup::Coupons => $this->findCoupons($term, $locale, $fallbackLocale, $offset),
            SearchGroup::Administrators => $this->findAdministrators($term, $offset),
            SearchGroup::Modules => $this->findModules($term, $locale, $fallbackLocale, $offset),
        };
    }

    /**
     * A product by its reference, the reference or the GTIN of one of its combinations, or its
     * title. The row names the combination whose reference matched, when it is not the
     * product's own.
     */
    private function findProducts(SearchTerm $term, string $locale, string $fallbackLocale, int $offset): SearchGroupResult
    {
        [$label, $from, $fromParameters, $title] = $this->translatedFrom('product', 'p', 'p.ref', $locale, $fallbackLocale);

        $select = new SqlParameters();
        $selectSql = 'p.id AS id, p.ref AS ref, '.$label.' AS label, CASE'
            .' WHEN p.ref = '.$select->add($term->raw).' OR '.$title.' = '.$select->add($term->raw)
            .' OR EXISTS (SELECT 1 FROM `product_sale_elements` v WHERE v.product_id = p.id AND ('
            .'v.ref = '.$select->add($term->raw).$this->orGtin('v.ean_code', $term, $select).')) THEN '.self::EXACT
            .' WHEN '.$select->like('p.ref', $term->prefix).' OR '.$select->like($title, $term->prefix)
            .' OR EXISTS (SELECT 1 FROM `product_sale_elements` v WHERE v.product_id = p.id AND '.$select->like('v.ref', $term->prefix).') THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '('.$where->like('p.ref', $term->contains)
            .' OR '.$this->titleExists('product', 'p', $term, $where)
            .' OR EXISTS (SELECT 1 FROM `product_sale_elements` v WHERE v.product_id = p.id AND ('
            .$where->like('v.ref', $term->contains).$this->orGtin('v.ean_code', $term, $where).')))';

        $result = $this->fetch($selectSql, $select, $from, $fromParameters, $whereSql, $where, 'label, id', $offset);
        $matched = $this->matchedCombinations($result->rows, $term);

        return new SearchGroupResult(
            array_map(static fn (array $row): array => $row + ['combination_ref' => $matched[(int) $row['id']] ?? null], $result->rows),
            $result->total,
        );
    }

    /**
     * The combination each product of the page was found through, when its own reference does not
     * hold the term: read for the shown rows only, once they are sorted and cut.
     *
     * @param list<array<string, int|string|null>> $products
     *
     * @return array<int, string> the reference of the combination, by product id
     */
    private function matchedCombinations(array $products, SearchTerm $term): array
    {
        $productIds = [];
        foreach ($products as $product) {
            if (false === mb_stripos((string) $product['ref'], $term->raw)) {
                $productIds[] = (int) $product['id'];
            }
        }

        if ([] === $productIds) {
            return [];
        }

        $parameters = new SqlParameters();
        $rows = $this->rows(
            'SELECT v.product_id AS product_id, v.ref AS ref FROM `product_sale_elements` v JOIN `product` p ON p.id = v.product_id'
            .' WHERE v.product_id IN ('.implode(', ', $productIds).') AND v.ref <> p.ref AND ('
            .$parameters->like('v.ref', $term->contains).$this->orGtin('v.ean_code', $term, $parameters).')'
            .' ORDER BY v.ref = '.$parameters->add($term->raw).' DESC, v.id',
            $parameters->values(),
        );

        $matched = [];
        foreach ($rows as $row) {
            $matched[(int) $row['product_id']] ??= (string) $row['ref'];
        }

        return $matched;
    }

    /**
     * A combination by its reference or its GTIN, with its product. A combination carrying the
     * reference of its product is the product itself, already in the products group: it is left
     * out, unless its GTIN is what matched.
     */
    private function findCombinations(SearchTerm $term, string $locale, string $fallbackLocale, int $offset): SearchGroupResult
    {
        [$label, $from, $fromParameters] = $this->translatedFrom('product', 'p', 'p.ref', $locale, $fallbackLocale, '`product_sale_elements` v JOIN `product` p ON p.id = v.product_id');

        $select = new SqlParameters();
        $selectSql = 'v.id AS id, v.ref AS ref, v.ean_code AS ean_code, p.id AS product_id, p.ref AS product_ref, '.$label.' AS label, CASE'
            .' WHEN v.ref = '.$select->add($term->raw).$this->orGtin('v.ean_code', $term, $select).' THEN '.self::EXACT
            .' WHEN '.$select->like('v.ref', $term->prefix).' THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '((v.ref <> p.ref AND '.$where->like('v.ref', $term->contains).')'
            .$this->orGtin('v.ean_code', $term, $where).')';

        $result = $this->fetch($selectSql, $select, $from, $fromParameters, $whereSql, $where, 'label, ref, id', $offset);
        $attributes = $this->attributeLabels(array_map(static fn (array $row): int => (int) $row['id'], $result->rows), $locale, $fallbackLocale);

        return new SearchGroupResult(
            array_map(static fn (array $row): array => $row + ['attributes' => $attributes[(int) $row['id']] ?? ''], $result->rows),
            $result->total,
        );
    }

    /**
     * A catalogue or content object by its title, in any language.
     *
     * @param 'category'|'folder'|'content'|'brand'|'sale' $table
     */
    private function findTranslated(string $table, SearchTerm $term, string $locale, string $fallbackLocale, int $offset): SearchGroupResult
    {
        [$label, $from, $fromParameters, $title] = $this->translatedFrom($table, 't', "CONCAT('#', t.id)", $locale, $fallbackLocale);

        $select = new SqlParameters();
        $selectSql = 't.id AS id, '.$label.' AS label, CASE'
            .' WHEN '.$title.' = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like($title, $term->prefix).' THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = $this->titleExists($table, 't', $term, $where);

        return $this->fetch($selectSql, $select, $from, $fromParameters, $whereSql, $where, 'label, id', $offset);
    }

    /** A customer by reference, name, email address or the phone number of one of their addresses. */
    private function findCustomers(SearchTerm $term, int $offset): SearchGroupResult
    {
        // The phone condition refers to the customer table by its name: no alias here.
        $name = "CONCAT_WS(' ', customer.firstname, customer.lastname)";

        $select = new SqlParameters();
        $selectSql = 'customer.id AS id, customer.ref AS ref, customer.firstname AS firstname, customer.lastname AS lastname,'
            .' customer.email AS email, '.$name.' AS label, CASE'
            .' WHEN customer.ref = '.$select->add($term->raw).' OR customer.email = '.$select->add($term->raw)
            .' OR '.$name.' = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like('customer.ref', $term->prefix).' OR '.$select->like('customer.lastname', $term->prefix)
            .' OR '.$select->like('customer.firstname', $term->prefix).' OR '.$select->like('customer.email', $term->prefix)
            .' THEN '.self::PREFIX.' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '('.$where->like('customer.ref', $term->contains)
            .' OR '.$where->like('customer.firstname', $term->contains)
            .' OR '.$where->like('customer.lastname', $term->contains)
            .' OR '.$where->like('customer.email', $term->contains)
            .' OR '.$where->like($name, $term->contains);

        $phoneDigits = CustomerPhoneSearch::digits($term->raw, CustomerPhoneSearch::SEARCH_BOX_MINIMUM_DIGITS);
        if (null !== $phoneDigits) {
            $where->add(CustomerPhoneSearch::pattern($phoneDigits));
            $whereSql .= ' OR '.CustomerPhoneSearch::clause();
        }
        $whereSql .= ')';

        return $this->fetch($selectSql, $select, '`customer`', new SqlParameters(), $whereSql, $where, 'label, id', $offset);
    }

    /** An order by its reference, its invoice reference or its transaction reference, the latest first. */
    private function findOrders(SearchTerm $term, int $offset): SearchGroupResult
    {
        $select = new SqlParameters();
        $selectSql = 'o.id AS id, o.ref AS ref, o.invoice_ref AS invoice_ref, o.ref AS label, CASE'
            .' WHEN o.ref = '.$select->add($term->raw).' OR o.invoice_ref = '.$select->add($term->raw)
            .' OR o.transaction_ref = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like('o.ref', $term->prefix).' OR '.$select->like('o.invoice_ref', $term->prefix)
            .' OR '.$select->like('o.transaction_ref', $term->prefix).' THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        // The alternatives in parentheses, and the sale units of the administrator as one more condition they all meet.
        $whereSql = '('.$where->like('o.ref', $term->contains)
            .' OR '.$where->like('o.invoice_ref', $term->contains)
            .' OR '.$where->like('o.transaction_ref', $term->contains).')'
            .$this->orderAccess->sqlCondition('o.sale_unit_id');

        return $this->fetch($selectSql, $select, '`order` o', new SqlParameters(), $whereSql, $where, 'id DESC', $offset);
    }

    /** A coupon by its code or its title, in any language. */
    private function findCoupons(SearchTerm $term, string $locale, string $fallbackLocale, int $offset): SearchGroupResult
    {
        [$label, $from, $fromParameters, $title] = $this->translatedFrom('coupon', 'c', 'c.code', $locale, $fallbackLocale);

        $select = new SqlParameters();
        $selectSql = 'c.id AS id, c.code AS code, '.$label.' AS label, CASE'
            .' WHEN c.code = '.$select->add($term->raw).' OR '.$title.' = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like('c.code', $term->prefix).' OR '.$select->like($title, $term->prefix).' THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '('.$where->like('c.code', $term->contains).' OR '.$this->titleExists('coupon', 'c', $term, $where).')';

        return $this->fetch($selectSql, $select, $from, $fromParameters, $whereSql, $where, 'label, id', $offset);
    }

    /** An administrator by login, name or email address. */
    private function findAdministrators(SearchTerm $term, int $offset): SearchGroupResult
    {
        $name = "CONCAT_WS(' ', a.firstname, a.lastname)";

        $select = new SqlParameters();
        $selectSql = 'a.id AS id, a.login AS login, a.email AS email, '.$name.' AS label, CASE'
            .' WHEN a.login = '.$select->add($term->raw).' OR a.email = '.$select->add($term->raw)
            .' OR '.$name.' = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like('a.login', $term->prefix).' OR '.$select->like('a.lastname', $term->prefix)
            .' OR '.$select->like('a.firstname', $term->prefix).' OR '.$select->like('a.email', $term->prefix)
            .' THEN '.self::PREFIX.' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '('.$where->like('a.login', $term->contains)
            .' OR '.$where->like('a.firstname', $term->contains)
            .' OR '.$where->like('a.lastname', $term->contains)
            .' OR '.$where->like('a.email', $term->contains)
            .' OR '.$where->like($name, $term->contains).')';

        return $this->fetch($selectSql, $select, '`admin` a', new SqlParameters(), $whereSql, $where, 'label, id', $offset);
    }

    /** A module by its code or its title, in any language. */
    private function findModules(SearchTerm $term, string $locale, string $fallbackLocale, int $offset): SearchGroupResult
    {
        [$label, $from, $fromParameters, $title] = $this->translatedFrom('module', 'm', 'm.code', $locale, $fallbackLocale);

        $select = new SqlParameters();
        $selectSql = 'm.id AS id, m.code AS code, '.$label.' AS label, CASE'
            .' WHEN m.code = '.$select->add($term->raw).' OR '.$title.' = '.$select->add($term->raw).' THEN '.self::EXACT
            .' WHEN '.$select->like('m.code', $term->prefix).' OR '.$select->like($title, $term->prefix).' THEN '.self::PREFIX
            .' ELSE 2 END AS score';

        $where = new SqlParameters();
        $whereSql = '('.$where->like('m.code', $term->contains).' OR '.$this->titleExists('module', 'm', $term, $where).')';

        return $this->fetch($selectSql, $select, $from, $fromParameters, $whereSql, $where, 'label, id', $offset);
    }

    /**
     * The table joined twice to its translations, in the locale of the administrator and in the
     * fallback locale, and the title read from the first that is not empty, then from any other
     * language. $joinedTo replaces the table alone when it is reached through another one.
     *
     * @return array{string, string, SqlParameters, string} the title shown, the FROM clause, its parameters, and the
     *                                                     title in the two languages alone, cheap enough to score every row
     */
    private function translatedFrom(string $table, string $alias, string $untitled, string $locale, string $fallbackLocale, ?string $joinedTo = null): array
    {
        $parameters = new SqlParameters();
        $from = ($joinedTo ?? '`'.$table.'` '.$alias)
            .' LEFT JOIN `'.$table.'_i18n` '.$alias.'_title ON '.$alias.'_title.id = '.$alias.'.id AND '.$alias.'_title.locale = '.$parameters->add($locale)
            .' LEFT JOIN `'.$table.'_i18n` '.$alias.'_fallback ON '.$alias.'_fallback.id = '.$alias.'.id AND '.$alias.'_fallback.locale = '.$parameters->add($fallbackLocale);

        // A row found by a title in a third language shows that title rather than no title at all.
        $label = 'COALESCE(NULLIF('.$alias.'_title.title, \'\'), NULLIF('.$alias.'_fallback.title, \'\'),'
            .' (SELECT '.$alias.'_any.title FROM `'.$table.'_i18n` '.$alias.'_any WHERE '.$alias.'_any.id = '.$alias.'.id'
            .' AND '.$alias.'_any.title <> \'\' ORDER BY '.$alias.'_any.locale LIMIT 1), '.$untitled.')';
        $title = 'COALESCE(NULLIF('.$alias.'_title.title, \'\'), '.$alias.'_fallback.title, \'\')';

        return [$label, $from, $parameters, $title];
    }

    /** True when a title of the row, in any language, holds the term. */
    private function titleExists(string $table, string $alias, SearchTerm $term, SqlParameters $parameters): string
    {
        return 'EXISTS (SELECT 1 FROM `'.$table.'_i18n` '.$alias.'_match WHERE '.$alias.'_match.id = '.$alias.'.id AND '
            .$parameters->like($alias.'_match.title', $term->contains).')';
    }

    /** An exact GTIN comparison added to a condition when the term reads as a GTIN, nothing otherwise. */
    private function orGtin(string $column, SearchTerm $term, SqlParameters $parameters): string
    {
        return null === $term->gtin ? '' : ' OR '.$column.' = '.$parameters->add($term->gtin);
    }

    private function fetch(
        string $select,
        SqlParameters $selectParameters,
        string $from,
        SqlParameters $fromParameters,
        string $where,
        SqlParameters $whereParameters,
        string $tieBreak,
        int $offset,
    ): SearchGroupResult {
        $rows = $this->rows(
            'SELECT '.$select.' FROM '.$from.' WHERE '.$where
            .' ORDER BY score, '.$tieBreak.' LIMIT '.$offset.', '.(self::DISPLAY_LIMIT + 1),
            [...$selectParameters->values(), ...$fromParameters->values(), ...$whereParameters->values()],
        );

        if (\count($rows) <= self::DISPLAY_LIMIT && 0 === $offset) {
            return new SearchGroupResult($rows, \count($rows));
        }

        $count = $this->rows(
            'SELECT COUNT(*) AS total FROM '.$from.' WHERE '.$where,
            [...$fromParameters->values(), ...$whereParameters->values()],
        );

        return new SearchGroupResult(\array_slice($rows, 0, self::DISPLAY_LIMIT), (int) ($count[0]['total'] ?? 0));
    }

    /**
     * The attribute values of each combination, joined by a slash, in the administrator's language.
     *
     * @param list<int> $combinationIds
     *
     * @return array<int, string>
     */
    private function attributeLabels(array $combinationIds, string $locale, string $fallbackLocale): array
    {
        if ([] === $combinationIds) {
            return [];
        }

        $parameters = new SqlParameters();
        $rows = $this->rows(
            'SELECT ac.product_sale_elements_id AS id, GROUP_CONCAT(COALESCE(NULLIF(av_title.title, \'\'), NULLIF(av_fallback.title, \'\'), \'\')'
            .' ORDER BY ac.attribute_id SEPARATOR \' / \') AS label'
            .' FROM `attribute_combination` ac'
            .' LEFT JOIN `attribute_av_i18n` av_title ON av_title.id = ac.attribute_av_id AND av_title.locale = '.$parameters->add($locale)
            .' LEFT JOIN `attribute_av_i18n` av_fallback ON av_fallback.id = ac.attribute_av_id AND av_fallback.locale = '.$parameters->add($fallbackLocale)
            .' WHERE ac.product_sale_elements_id IN ('.implode(', ', array_map('intval', $combinationIds)).')'
            .' GROUP BY ac.product_sale_elements_id',
            $parameters->values(),
        );

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['id']] = trim((string) $row['label'], ' /');
        }

        return $labels;
    }

    /**
     * @param list<string> $values
     *
     * @return list<array<string, int|string|null>>
     */
    private function rows(string $sql, array $values): array
    {
        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($values);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }
}

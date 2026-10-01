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

use Propel\Runtime\Propel;

/**
 * The carts of one customer that never became an order, for the customer sheet.
 *
 * The database has no "abandoned" flag: a cart became an order when an order points to it
 * (`order.cart_id`, a column with no foreign key), and a cart line is what makes a cart
 * worth showing. The amounts are the lines' own prices, excluding tax, as the cart stores
 * them: the taxed figure depends on a delivery country the cart may not have. A line a
 * promotion offered is free and left out of the amounts, as the core cart total leaves it
 * out (BaseFacade::getCartTotalPrice()).
 */
final readonly class CustomerCartRepository
{
    /**
     * The customer's cart with at least one line and no order, created on or after
     * $createdSince and active on or after $activeSince: the most recently active one.
     *
     * @return array{id: int, currency_id: ?int, created_at: \DateTimeImmutable, last_activity_at: \DateTimeImmutable, line_count: int, quantity: float, amount: float}|null
     */
    public function findCurrentCart(int $customerId, \DateTimeImmutable $createdSince, \DateTimeImmutable $activeSince): ?array
    {
        return $this->findUnconvertedCarts($customerId, $createdSince, 'last_activity_at >= :active', $activeSince, 1)[0] ?? null;
    }

    /**
     * The customer's carts with at least one line and no order, created on or after
     * $createdSince and last active before $activeSince, the most recently active first.
     *
     * @return list<array{id: int, currency_id: ?int, created_at: \DateTimeImmutable, last_activity_at: \DateTimeImmutable, line_count: int, quantity: float, amount: float}>
     */
    public function findAbandonedCarts(int $customerId, \DateTimeImmutable $createdSince, \DateTimeImmutable $activeSince, int $limit): array
    {
        return $this->findUnconvertedCarts($customerId, $createdSince, 'last_activity_at < :active', $activeSince, $limit);
    }

    /**
     * A cart's last activity is the later of its own update and its lines' updates: adding
     * a line does not touch the cart row. A missing date falls back on the creation date,
     * as GREATEST() would otherwise answer NULL.
     *
     * @param 'last_activity_at >= :active'|'last_activity_at < :active' $activity
     *
     * @return list<array{id: int, currency_id: ?int, created_at: \DateTimeImmutable, last_activity_at: \DateTimeImmutable, line_count: int, quantity: float, amount: float}>
     */
    private function findUnconvertedCarts(int $customerId, \DateTimeImmutable $createdSince, string $activity, \DateTimeImmutable $activeSince, int $limit): array
    {
        $sql = 'SELECT c.id, c.currency_id, c.created_at,
                GREATEST(COALESCE(c.updated_at, c.created_at), COALESCE(MAX(ci.updated_at), c.created_at)) AS last_activity_at,
                COUNT(ci.id) AS line_count,
                SUM(ci.quantity) AS quantity,
                SUM(CASE WHEN ci.is_offered = 1 THEN 0 ELSE ci.quantity * CASE WHEN ci.promo = 1 THEN ci.promo_price ELSE ci.price END END) AS amount
            FROM cart c
            JOIN cart_item ci ON ci.cart_id = c.id
            WHERE c.customer_id = :customer
              AND c.created_at >= :since
              AND NOT EXISTS (SELECT 1 FROM `order` o WHERE o.cart_id = c.id)
            GROUP BY c.id, c.currency_id, c.created_at, c.updated_at
            HAVING '.$activity.'
            ORDER BY last_activity_at DESC, c.id DESC
            LIMIT '.max(1, $limit);

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([
            ':customer' => $customerId,
            ':since' => $createdSince->format('Y-m-d H:i:s'),
            ':active' => $activeSince->format('Y-m-d H:i:s'),
        ]);

        $carts = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $carts[] = [
                'id' => (int) $row['id'],
                'currency_id' => $row['currency_id'] !== null ? (int) $row['currency_id'] : null,
                'created_at' => new \DateTimeImmutable((string) $row['created_at']),
                'last_activity_at' => new \DateTimeImmutable((string) $row['last_activity_at']),
                'line_count' => (int) $row['line_count'],
                // DECIMAL and FLOAT columns come back as strings.
                'quantity' => (float) $row['quantity'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        return $carts;
    }

    /**
     * The lines of one cart with their product title in $locale, in one query.
     *
     * @return list<array{product_id: ?int, title: string, reference: string, quantity: float, unit_price: float, line_total: float, is_promo: bool, is_offered: bool}>
     */
    public function findCartLines(int $cartId, string $locale): array
    {
        $sql = 'SELECT ci.product_id, ci.quantity, ci.price, ci.promo_price, ci.promo, ci.is_offered,
                p.ref AS product_ref, pi.title AS product_title, pse.ref AS pse_ref
            FROM cart_item ci
            LEFT JOIN product p ON p.id = ci.product_id
            LEFT JOIN product_i18n pi ON pi.id = ci.product_id AND pi.locale = :locale
            LEFT JOIN product_sale_elements pse ON pse.id = ci.product_sale_elements_id
            WHERE ci.cart_id = :cart
            ORDER BY ci.id';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':cart' => $cartId, ':locale' => $locale]);

        $lines = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $isPromo = (int) $row['promo'] === 1;
            $unitPrice = (float) ($isPromo ? $row['promo_price'] : $row['price']);
            $quantity = (float) $row['quantity'];
            $reference = (string) ($row['pse_ref'] ?: $row['product_ref'] ?: '');

            $lines[] = [
                'product_id' => $row['product_ref'] !== null ? (int) $row['product_id'] : null,
                'title' => (string) ($row['product_title'] ?: $reference),
                'reference' => $reference,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => (int) $row['is_offered'] === 1 ? 0.0 : round($unitPrice * $quantity, 2),
                'is_promo' => $isPromo,
                'is_offered' => (int) $row['is_offered'] === 1,
            ];
        }

        return $lines;
    }
}

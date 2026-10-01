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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerCart;
use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerCartLine;
use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerCarts;
use BackOfficeDefaultTwigBundle\Repository\CustomerCartRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;

/**
 * Splits the customer's carts that never became an order into the one being filled and
 * the abandoned ones.
 *
 * A cart is current while its last activity is less than a day old, and abandoned after
 * that. Only the most recent current cart is shown: the shop duplicates a cart when the
 * customer logs in, and the copy left behind is not a cart the customer is filling.
 *
 * Carts older than the purge horizon are not looked for. The maintenance purge deletes the
 * carts of a customer that have no order once they are that old (on their creation date,
 * which is the date read here), so the section shows exactly what the database still holds.
 */
final readonly class CustomerCartsProvider
{
    /** Same key and default as the core cart purge, `maintenance:purge` (Thelia\Command\MaintenancePurgeCommand). */
    public const CART_NO_ORDER_DAYS_CONFIG_KEY = 'purification_cart_no_order_days';
    public const DEFAULT_CART_NO_ORDER_DAYS = 60;

    private const CURRENT_CART_WINDOW = 'PT24H';
    private const MAX_ABANDONED = 20;

    public function __construct(
        private CustomerCartRepository $carts,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function compute(int $customerId, string $locale, \DateTimeImmutable $now): CustomerCarts
    {
        $horizonDays = max(1, (int) ConfigQuery::read(self::CART_NO_ORDER_DAYS_CONFIG_KEY, self::DEFAULT_CART_NO_ORDER_DAYS));
        $createdSince = $now->modify('-'.$horizonDays.' days');
        $activeSince = $now->sub(new \DateInterval(self::CURRENT_CART_WINDOW));

        $currentRow = $this->carts->findCurrentCart($customerId, $createdSince, $activeSince);
        // One more than shown, to tell whether some are left out.
        $abandonedRows = $this->carts->findAbandonedCarts($customerId, $createdSince, $activeSince, self::MAX_ABANDONED + 1);
        $symbols = $this->currencySymbols($currentRow !== null ? [$currentRow, ...$abandonedRows] : $abandonedRows);

        $current = $currentRow !== null ? $this->cart($currentRow, $symbols) : null;

        return new CustomerCarts(
            current: $current,
            currentLines: $current !== null ? $this->lines($current->id, $locale) : [],
            abandoned: array_map(
                fn (array $row): CustomerCart => $this->cart($row, $symbols),
                \array_slice($abandonedRows, 0, self::MAX_ABANDONED),
            ),
            horizonDays: $horizonDays,
            moreAbandoned: \count($abandonedRows) > self::MAX_ABANDONED,
        );
    }

    /**
     * @param array{id: int, currency_id: ?int, created_at: \DateTimeImmutable, last_activity_at: \DateTimeImmutable, line_count: int, quantity: float, amount: float} $row
     * @param array<int, string>                                                                                                                                       $symbols
     */
    private function cart(array $row, array $symbols): CustomerCart
    {
        return new CustomerCart(
            id: $row['id'],
            createdAt: $row['created_at'],
            lastActivityAt: $row['last_activity_at'],
            lineCount: $row['line_count'],
            quantity: $row['quantity'],
            amount: $row['amount'],
            currencySymbol: $symbols[$row['currency_id'] ?? 0] ?? $symbols[0],
        );
    }

    /**
     * @return list<CustomerCartLine>
     */
    private function lines(int $cartId, string $locale): array
    {
        return array_map(
            fn (array $line): CustomerCartLine => new CustomerCartLine(
                title: $line['title'],
                reference: $line['reference'],
                quantity: $line['quantity'],
                unitPrice: $line['unit_price'],
                lineTotal: $line['line_total'],
                isPromo: $line['is_promo'],
                isOffered: $line['is_offered'],
                productUrl: $line['product_id'] !== null
                    ? $this->urls->generate('admin.products.update', ['product_id' => $line['product_id']])
                    : null,
            ),
            $this->carts->findCartLines($cartId, $locale),
        );
    }

    /**
     * Currency symbol per currency id, the shop's default under key 0.
     *
     * @param list<array{currency_id: ?int}> $rows
     *
     * @return array<int, string>
     */
    private function currencySymbols(array $rows): array
    {
        $symbols = [0 => (string) Currency::getDefaultCurrency()->getSymbol()];
        $ids = array_values(array_unique(array_filter(array_column($rows, 'currency_id'))));

        if ($ids !== []) {
            foreach (CurrencyQuery::create()->filterById($ids)->find() as $currency) {
                $symbols[(int) $currency->getId()] = (string) $currency->getSymbol();
            }
        }

        return $symbols;
    }
}

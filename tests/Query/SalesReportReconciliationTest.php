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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * The sales of the core calculator add up to the revenue of the dashboard: the lines, less the
 * discounts, plus the postage, are what OrderRepository::getRevenue() sums, and the roots of the
 * categories share out the lines.
 */
final class SalesReportReconciliationTest extends IntegrationTestCase
{
    private const CALCULATOR = 'Thelia\Domain\Report\Sales\SalesReportCalculator';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(self::CALCULATOR)) {
            self::markTestSkipped('The core predates the sales reports: nothing to reconcile.');
        }
    }

    public function testTheTotalOfTheReconciliationIsTheRevenueOfTheDashboard(): void
    {
        $this->paidOrder([['price' => '12.345600', 'tax' => '2.469100', 'quantity' => 3], ['price' => '0.999900', 'tax' => '0.199980', 'quantity' => 7]], '1.500000', '4.900000');
        $this->paidOrder([['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1]], '500.000000', '7.500000');
        $this->paidOrder([['price' => '1.000000', 'tax' => '0.005000', 'quantity' => 3]], '0', '0');
        $this->paidOrder([['price' => '20.000000', 'tax' => '4.000000', 'quantity' => 2, 'promo' => true, 'promoPrice' => '13.333300', 'promoTax' => '2.666660']], '0', '3.100000');

        $range = new DateRange(new \DateTimeImmutable('2000-01-01 00:00:00'), new \DateTimeImmutable('2100-01-01 00:00:00'), DateRange::PRESET_CUSTOM);
        $criteria = new \Thelia\Domain\Report\Sales\SalesReportCriteria($range->from, $range->to);

        $reconciliation = (new \Thelia\Domain\Report\Sales\SalesReportCalculator())->reconciliation($criteria);
        $revenue = (new OrderRepository(new OrderAccess(new AdminSaleUnitScope())))->getRevenue($range);

        self::assertEqualsWithDelta($revenue, $reconciliation->linesTotal - $reconciliation->discounts + $reconciliation->postage, 0.005);
        self::assertEqualsWithDelta($revenue, $reconciliation->revenue, 0.005);
        self::assertGreaterThan(0.0, $reconciliation->discounts);
        self::assertGreaterThan(0.0, $reconciliation->postage);
    }

    public function testTheRootsOfTheCategoriesShareOutTheLines(): void
    {
        $this->paidOrder([['price' => '12.345600', 'tax' => '2.469100', 'quantity' => 3], ['price' => '99.990000', 'tax' => '19.998000', 'quantity' => 1]], '0', '0');
        $this->paidOrder([['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 2]], '0', '2.000000');

        $criteria = new \Thelia\Domain\Report\Sales\SalesReportCriteria(new \DateTimeImmutable('2000-01-01 00:00:00'), new \DateTimeImmutable('2100-01-01 00:00:00'));
        $calculator = new \Thelia\Domain\Report\Sales\SalesReportCalculator();

        $roots = 0.0;
        foreach ($calculator->byCategory($criteria, 'en_US') as $row) {
            if (null === $row->parentId) {
                $roots += $row->revenue;
            }
        }

        self::assertEqualsWithDelta($calculator->reconciliation($criteria)->linesTotal, $roots, 0.005);
        self::assertGreaterThan(0.0, $roots);
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function paidOrder(array $lines, string $discount, string $postage): void
    {
        $order = $this->createFixtureFactory()->order(null, ['postage' => $postage, 'statusCode' => OrderStatus::CODE_PAID]);
        $order->setDiscount($discount)->save($this->getPropelConnection());

        foreach ($lines as $index => $line) {
            $orderProduct = (new OrderProduct())
                ->setOrderId($order->getId())
                ->setProductRef('REF-'.$order->getId().'-'.$index)
                ->setProductSaleElementsRef('PSE-'.$order->getId().'-'.$index)
                ->setTitle('Line '.$index)
                ->setQuantity((float) $line['quantity'])
                ->setPrice((string) $line['price'])
                ->setPromoPrice((string) ($line['promoPrice'] ?? $line['price']))
                ->setWasNew(0)
                ->setWasInPromo(($line['promo'] ?? false) ? 1 : 0);
            $orderProduct->save($this->getPropelConnection());

            (new OrderProductTax())
                ->setOrderProductId($orderProduct->getId())
                ->setTitle('VAT')
                ->setAmount((string) $line['tax'])
                ->setPromoAmount((string) ($line['promoTax'] ?? $line['tax']))
                ->save($this->getPropelConnection());
        }
    }
}

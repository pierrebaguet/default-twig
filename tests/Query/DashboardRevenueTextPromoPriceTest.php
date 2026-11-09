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
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * An order placed while order_product.promo_price was a text keeps the total it was invoiced
 * with: the revenue of the dashboard has to state the amount Order::getTotalAmount() states.
 */
final class DashboardRevenueTextPromoPriceTest extends IntegrationTestCase
{
    private const TEXT_PROMO_PRICE_PIVOT = 'last_text_promo_price_order_id';

    private ?string $pivotBefore = null;

    protected function tearDown(): void
    {
        // ConfigQuery keeps a static cache, which the rollback does not reach.
        if (null !== $this->pivotBefore) {
            ConfigQuery::write(self::TEXT_PROMO_PRICE_PIVOT, $this->pivotBefore);
            $this->pivotBefore = null;
        }

        parent::tearDown();
    }

    public function testTheRevenueOfAnOrderPlacedBeforeThePivotIsTheAmountOfTheModel(): void
    {
        // 1.005 rounds to 1.01 read as a decimal and to 1.00 read as a text, which a double
        // cannot hold exactly.
        $order = $this->paidOrderWithAPromoLine('1.005000');
        $this->pivotBefore = (string) ConfigQuery::read(self::TEXT_PROMO_PRICE_PIVOT, '0');
        ConfigQuery::write(self::TEXT_PROMO_PRICE_PIVOT, (string) $order->getId());

        $revenue = $this->revenueAddedBy($order);

        self::assertSame(
            number_format($order->getTotalAmount(), 2, '.', ''),
            number_format($revenue, 2, '.', ''),
            'The dashboard revenue reads the promotional price of this order as the model does.',
        );
    }

    public function testTheRevenueOfAnOrderAfterThePivotIsReadAsADecimal(): void
    {
        $order = $this->paidOrderWithAPromoLine('1.005000');
        $this->pivotBefore = (string) ConfigQuery::read(self::TEXT_PROMO_PRICE_PIVOT, '0');
        ConfigQuery::write(self::TEXT_PROMO_PRICE_PIVOT, (string) ((int) $order->getId() - 1));

        $revenue = $this->revenueAddedBy($order);

        self::assertSame(number_format($order->getTotalAmount(), 2, '.', ''), number_format($revenue, 2, '.', ''));
    }

    public function testThePivotChangesTheAmountForThisOrder(): void
    {
        // Guards the fixture: were both readings equal, the other cases would prove nothing.
        $order = $this->paidOrderWithAPromoLine('1.005000');
        $this->pivotBefore = (string) ConfigQuery::read(self::TEXT_PROMO_PRICE_PIVOT, '0');

        ConfigQuery::write(self::TEXT_PROMO_PRICE_PIVOT, '0');
        $asDecimal = $this->revenueAddedBy($order);
        ConfigQuery::write(self::TEXT_PROMO_PRICE_PIVOT, (string) $order->getId());
        $asText = $this->revenueAddedBy($order);

        self::assertNotSame(number_format($asDecimal, 2, '.', ''), number_format($asText, 2, '.', ''));
    }

    private function revenueAddedBy(Order $order): float
    {
        $repository = new OrderRepository(new OrderAccess(new AdminSaleUnitScope()));
        $range = new DateRange(
            new \DateTimeImmutable('2000-01-01 00:00:00'),
            new \DateTimeImmutable('2100-01-01 00:00:00'),
            DateRange::PRESET_CUSTOM,
        );

        $with = $repository->getRevenue($range);

        $order->setStatusId((int) OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_NOT_PAID)?->getId())->save($this->getPropelConnection());
        $without = $repository->getRevenue($range);

        $order->setStatusId((int) OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID)?->getId())->save($this->getPropelConnection());

        return $with - $without;
    }

    private function paidOrderWithAPromoLine(string $promoPrice): Order
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        $line = (new OrderProduct())
            ->setOrderId($order->getId())
            ->setProductRef('REF-TEXT-PROMO')
            ->setProductSaleElementsRef('PSE-TEXT-PROMO')
            ->setTitle('Line')
            ->setQuantity(1.0)
            ->setPrice('2.000000')
            ->setPromoPrice($promoPrice)
            ->setWasNew(0)
            ->setWasInPromo(1);
        $line->save($this->getPropelConnection());

        (new OrderProductTax())
            ->setOrderProductId($line->getId())
            ->setTitle('VAT')
            ->setAmount('0.400000')
            ->setPromoAmount('0.000000')
            ->save($this->getPropelConnection());

        $order->reload(true, $this->getPropelConnection());

        return $order;
    }
}

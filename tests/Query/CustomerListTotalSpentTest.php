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

use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilters;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Model\Customer;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * "Total spent" means the money the shop earned from a customer, on the same statuses
 * as the dashboard revenue: paid, processing, sent, and any status of the shop's own
 * that answers for one of them. A cancelled, refunded or still unpaid order is not
 * money spent, and the column, its sort, its range filter and the slider bounds of
 * the customer list all have to agree on that.
 */
final class CustomerListTotalSpentTest extends IntegrationTestCase
{
    private CustomerRepository $customers;

    private FixtureFactory $factory;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers = new CustomerRepository(new OrderRepository());
        $this->factory = $this->createFixtureFactory();
        $this->marker = 'Spent'.bin2hex(random_bytes(4));
    }

    public function testTheColumnOnlyAddsUpTheOrdersTheShopEarned(): void
    {
        [$mixed, $paidOnly] = $this->twoCustomers();

        $totals = $this->customers->findTotalSpentByCustomer([(int) $mixed->getId(), (int) $paidOnly->getId()]);

        self::assertEqualsWithDelta(37.0, $totals[(int) $mixed->getId()], 0.001, 'Paid 30 plus 7 on a status answering for paid; the unpaid, cancelled and refunded orders are left out.');
        self::assertEqualsWithDelta(50.0, $totals[(int) $paidOnly->getId()], 0.001);
    }

    public function testTheSortRanksOnTheSameTotal(): void
    {
        [$mixed, $paidOnly] = $this->twoCustomers();

        $page = $this->customers->findPaginated($this->filters(['order' => 'total_spent', 'direction' => 'desc']), 1, 10);

        $ids = array_map(static fn (Customer $customer): int => (int) $customer->getId(), iterator_to_array($page['rows']));
        self::assertSame([(int) $paidOnly->getId(), (int) $mixed->getId()], $ids, 'The customer who spent 50 comes before the one who spent 37.');
    }

    public function testTheRangeFilterSelectsOnTheSameTotal(): void
    {
        [$mixed] = $this->twoCustomers();

        $page = $this->customers->findPaginated($this->filters(['min_total' => '36', 'max_total' => '38']), 1, 10);

        $ids = array_map(static fn (Customer $customer): int => (int) $customer->getId(), iterator_to_array($page['rows']));
        self::assertSame([(int) $mixed->getId()], $ids);
    }

    public function testACancelledOrderDoesNotStretchTheSliderBounds(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle(), ['lastname' => $this->marker]);
        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED, 'postage' => '900000']);

        self::assertLessThan(900000.0, $this->customers->getTotalSpentBounds()['max']);
    }

    /**
     * @return array{0: Customer, 1: Customer}
     */
    private function twoCustomers(): array
    {
        $title = $this->factory->customerTitle();
        $mixed = $this->factory->customer($title, ['lastname' => $this->marker]);
        $paidOnly = $this->factory->customer($title, ['lastname' => $this->marker]);

        $this->factory->order($mixed, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '30']);
        $this->factory->order($mixed, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'postage' => '100']);
        $this->factory->order($mixed, ['statusCode' => OrderStatus::CODE_CANCELED, 'postage' => '200']);
        $this->factory->order($mixed, ['statusCode' => OrderStatus::CODE_REFUNDED, 'postage' => '400']);

        $ownPaid = $this->factory->orderStatus(['equivalentCode' => OrderStatus::CODE_PAID]);
        $order = $this->factory->order($mixed, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'postage' => '7']);
        $order->setStatusId($ownPaid->getId())->save($this->getPropelConnection());

        $this->factory->order($paidOnly, ['statusCode' => OrderStatus::CODE_SENT, 'postage' => '50']);

        return [$mixed, $paidOnly];
    }

    /**
     * @param array<string, string> $query
     */
    private function filters(array $query): CustomerFilters
    {
        return CustomerFilters::fromRequest(new Request(['search' => $this->marker] + $query));
    }
}

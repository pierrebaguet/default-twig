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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\OrderStatus;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The customer sheet as a salesperson reads it: what the customer spent, bought, left in a
 * cart, and what the administrator is allowed to see of it.
 */
final class CustomerSheetTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    public function testTheFiguresLeaveTheCancelledOrderOut(): void
    {
        $customer = $this->customer();
        foreach ([OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_SENT, OrderStatus::CODE_PAID] as $index => $code) {
            $this->factory->order($customer, ['statusCode' => $code, 'postage' => (string) (10 * ($index + 1))]);
        }
        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED, 'postage' => '1000']);

        $crawler = $this->sheet($customer, $this->factory->admin());

        self::assertStringStartsWith('100.00 ', $this->text($crawler, 'customer-kpi-total-spent-value'));
        self::assertSame('4', $this->text($crawler, 'customer-kpi-order-count-value'));
        self::assertStringContainsString('1', $this->text($crawler, 'customer-kpi-order-count-note'), 'The cancelled order is said to be left out.');
        self::assertStringStartsWith('25.00 ', $this->text($crawler, 'customer-kpi-average-basket-value'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-kpi-total-spent-hint"]'), 'The definition is shown next to the figure.');
    }

    public function testACustomerWithoutOrderShowsZeroFiguresAndAnEmptyOrderList(): void
    {
        $crawler = $this->sheet($this->customer(), $this->factory->admin());

        self::assertStringStartsWith('0.00 ', $this->text($crawler, 'customer-kpi-total-spent-value'));
        self::assertSame('0', $this->text($crawler, 'customer-kpi-order-count-value'));
        self::assertSame('-', $this->text($crawler, 'customer-kpi-average-basket-value'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-orders-section"]'), 'An empty section is shown, not hidden.');
    }

    public function testAnAdministratorWhoMayNotSeeTheOrdersSeesNeitherTheOrdersNorTheirFigures(): void
    {
        $customer = $this->customer();
        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '10']);
        $admin = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);

        $crawler = $this->sheet($customer, $admin);

        self::assertCount(0, $crawler->filter('[data-testid="customer-kpi-total-spent"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-kpi-order-count"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-orders-section"]'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-kpi-customer-since"]'), 'The account age does not come from the orders.');
    }

    public function testTheCartInProgressShowsItsContentItsAmountAndALinkToTheProduct(): void
    {
        $customer = $this->customer();
        $product = $this->product('Linen jacket');
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $product, null, ['quantity' => 2.0, 'price' => '45.000000']);

        $crawler = $this->carts($customer, $this->factory->admin());

        self::assertStringStartsWith('90.00 ', $this->text($crawler, 'customer-current-cart-amount'));
        $link = $crawler->filter('[data-testid="customer-current-cart-product-link"]');
        self::assertCount(1, $link);
        self::assertSame('Linen jacket', trim($link->text()));
        self::assertStringContainsString('product_id='.$product->getId(), (string) $link->attr('href'));
    }

    public function testACartAbandonedThreeWeeksAgoIsListedAndDated(): void
    {
        $customer = $this->customer();
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product('Wool scarf'));
        $abandonedOn = new \DateTimeImmutable('-21 days');
        $date = $abandonedOn->format('Y-m-d H:i:s');
        $this->getPropelConnection()->prepare('UPDATE cart SET created_at = ?, updated_at = ? WHERE id = ?')->execute([$date, $date, $cart->getId()]);
        $this->getPropelConnection()->prepare('UPDATE cart_item SET created_at = ?, updated_at = ? WHERE cart_id = ?')->execute([$date, $date, $cart->getId()]);

        $crawler = $this->carts($customer, $this->factory->admin());

        self::assertCount(1, $crawler->filter('[data-testid="customer-current-cart-empty"]'));
        $rows = $crawler->filter('[data-testid="datatable-customer-abandoned-carts-row"]');
        self::assertCount(1, $rows);
        self::assertStringContainsString($abandonedOn->format('Y'), $rows->text(), 'The abandoned cart carries its date.');
    }

    public function testACustomerWhoNeverLeftACartIsToldSo(): void
    {
        $crawler = $this->carts($this->customer(), $this->factory->admin());

        self::assertCount(1, $crawler->filter('[data-testid="customer-current-cart-empty"]'));
        self::assertStringContainsString('60', $this->text($crawler, 'datatable-customer-abandoned-carts-empty'), 'The empty list says over which period.');
        self::assertCount(1, $crawler->filter('[data-testid="customer-carts-horizon"]'));
    }

    public function testTheCartsSectionIsRefusedWithoutTheCustomerPermission(): void
    {
        $customer = $this->customer();
        $admin = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', '/admin/customer/carts?customer_id='.$customer->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheCartsOfAnUnknownCustomerAreNotFound(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', '/admin/customer/carts?customer_id=987654321');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function product(string $title): Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['title' => $title],
        );
    }

    private function carts(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/carts?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function sheet(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function text(Crawler $crawler, string $testid): string
    {
        $node = $crawler->filter('[data-testid="'.$testid.'"]');
        self::assertCount(1, $node, $testid.' is on the page.');

        return trim(preg_replace('/\s+/u', ' ', $node->text()) ?? '');
    }
}

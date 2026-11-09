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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The "Reports > Sales" screen: the period, the orders counted, the three tabs, the rights each
 * asks for, and the export of the exact days on screen.
 */
final class SalesReportScreenTest extends WebIntegrationTestCase
{
    private const CATEGORY_URL = '/admin/reports/sales/category';

    private const PRODUCT_URL = '/admin/reports/sales/product';

    private const CUSTOMER_URL = '/admin/reports/sales/customer';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists('Thelia\Domain\Report\Sales\SalesReportCalculator')) {
            self::markTestSkipped('The core predates the sales reports: SalesReportWithoutCoreTest covers the screen without them.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testTheScreenOpensOnThirtyDaysByCategoryWithTheThreeTabs(): void
    {
        $this->paidOrder($this->customer('Alpha'), '100.000000', 1, '5.000000');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::CATEGORY_URL);

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-period-30days"]')->attr('class'));
        foreach (['category', 'product', 'customer'] as $tab) {
            self::assertCount(1, $crawler->filter('[data-testid="report-tab-'.$tab.'"]'), $tab);
        }
        self::assertStringContainsString('active', (string) $crawler->filter('[data-testid="report-tab-category"]')->attr('class'));
        self::assertCount(1, $crawler->filter('[data-testid="report-category-tree"]'));
        self::assertStringContainsString('Current category of the product', $crawler->filter('[data-testid="report-category-note"]')->text());
        foreach (['lines', 'discounts', 'postage', 'total'] as $line) {
            self::assertCount(1, $crawler->filter('[data-testid="report-reconciliation-'.$line.'"]'), $line);
        }
        self::assertStringContainsString('Only the paid orders', $crawler->filter('[data-testid="report-scope-label"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="report-sales-revenue"]'));
        self::assertCount(1, $crawler->filter('[data-testid="report-sales-revenue-variation"]'));

        $this->assertPageRenders(self::PRODUCT_URL);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="report-product-table"]'));
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('[data-testid="report-product-row"]')->count());
    }

    public function testAFreePeriodIsShownAndNoPillIsActive(): void
    {
        $this->loginAs($this->factory->admin());
        $from = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $to = (new \DateTimeImmutable('-2 days'))->format('Y-m-d');

        $this->assertPageRenders(self::CATEGORY_URL.'?period=custom&from='.$from.'&to='.$to);

        $crawler = $this->client->getCrawler();
        self::assertSame($from, $crawler->filter('[data-testid="report-from"]')->attr('value'));
        self::assertSame($to, $crawler->filter('[data-testid="report-to"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-testid="report-period-options"] .btn-primary'), 'A free period is not one of the pills.');
        self::assertCount(0, $crawler->filter('[data-testid="report-period-custom"]'));
    }

    public function testAnInvalidFreePeriodFallsBackOnThirtyDays(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::CATEGORY_URL.'?period=custom&from=2026-02-30&to=2026-03-01');

        self::assertStringContainsString('btn-primary', (string) $this->client->getCrawler()->filter('[data-testid="report-period-30days"]')->attr('class'));
    }

    public function testTheScopeSwitchCountsEveryOrderAndSaysSo(): void
    {
        $customer = $this->customer('Beta');
        $this->loginAs($this->factory->admin());
        $this->assertPageRenders(self::CATEGORY_URL);
        $paidBefore = $this->orderCount();

        $this->assertPageRenders(self::CATEGORY_URL.'?scope=all');
        $allBefore = $this->orderCount();
        self::assertStringContainsString('Every order created', $this->client->getCrawler()->filter('[data-testid="report-scope-label"]')->text());

        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertSame($paidBefore, $this->orderCount(), 'An order that is not paid is not counted by default.');
        $this->assertPageRenders(self::CATEGORY_URL.'?scope=all');
        self::assertSame($allBefore + 1, $this->orderCount(), 'It is counted when every order is.');
        self::assertStringContainsString('btn-primary', (string) $this->client->getCrawler()->filter('[data-testid="report-scope-all"]')->attr('class'));
    }

    public function testWithoutTheCustomerPermissionTheCustomerTabIsHiddenAndItsRouteRefused(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));

        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="report-tab-customer"]'));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="report-tab-product"]'));

        $this->client->request('GET', self::CUSTOMER_URL);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorWhoCannotSeeTheOrdersIsRefused(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]));

        foreach ([self::CATEGORY_URL, self::PRODUCT_URL, self::CUSTOMER_URL] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testCustomersAreSortedByTotalDescendingByDefaultAndEveryColumnCanBeSorted(): void
    {
        $small = $this->customer('Smallspender'.uniqid());
        $big = $this->customer('Bigspender'.uniqid());
        $this->paidOrder($small, '8000000.000000', 1, '0');
        $this->paidOrder($big, '9000000.000000', 1, '0');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::CUSTOMER_URL);
        $html = $this->html();
        self::assertLessThan(strpos($html, (string) $small->getLastname()), strpos($html, (string) $big->getLastname()), 'The biggest total comes first.');
        self::assertStringContainsString('bi-sort-down', (string) $this->client->getCrawler()->filter('[data-testid="sort-total"] i')->attr('class'));

        $this->assertPageRenders(self::CUSTOMER_URL.'?sort=total&dir=asc');
        $html = $this->html();
        self::assertLessThan(strpos($html, (string) $big->getLastname()), strpos($html, (string) $small->getLastname()), 'The direction is honoured.');

        foreach (['name', 'orders', 'average', 'last_order'] as $column) {
            $this->assertPageRenders(self::CUSTOMER_URL.'?sort='.$column);
            self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="sort-'.$column.'"]'), $column);
        }

        $this->assertPageRenders(self::CUSTOMER_URL.'?sort=name');
        self::assertStringContainsString('bi-sort-up', (string) $this->client->getCrawler()->filter('[data-testid="sort-name"] i')->attr('class'), 'Names open in alphabetical order.');

        $this->assertPageRenders(self::CUSTOMER_URL.'?sort=lastname;DROP');
        self::assertStringContainsString('bi-sort-down', (string) $this->client->getCrawler()->filter('[data-testid="sort-total"] i')->attr('class'), 'An unknown column falls back on the total.');
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('[data-testid="report-customer-link"]')->count());
    }

    public function testTheExportButtonFollowsThePermissionAndTheScope(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));
        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="report-export-button"]'), 'No export permission, no button.');

        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW], AdminResources::EXPORT => [AccessManager::VIEW]]));
        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="report-export-button"]'));

        $this->assertPageRenders(self::CATEGORY_URL.'?scope=all');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="report-export-button"]'), 'The export counts the paid orders: it is not offered beside every order.');
    }

    public function testTheFiguresAreKeptUntilAnOrderChangesOrTheyAreComputedAgain(): void
    {
        $customer = $this->customer('Kept'.uniqid());
        $this->paidOrder($customer, '1000000.000000', 1, '0');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::CATEGORY_URL);
        $first = $this->revenue();
        self::assertStringContainsString('Computed at', $this->client->getCrawler()->filter('[data-testid="report-computed-at"]')->text());

        // A line changed behind the orders' back: the orders are the same, the figures are kept.
        $this->getPropelConnection()->exec("UPDATE order_product SET price = price + 500000 WHERE price = '1000000.000000'");
        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertSame($first, $this->revenue(), 'The figures computed a moment ago are shown again.');

        $refresh = (string) $this->client->getCrawler()->filter('[data-testid="report-refresh"]')->attr('href');
        self::assertStringContainsString('refresh=1', $refresh);
        $this->assertPageRenders($refresh);
        $refreshed = $this->revenue();
        self::assertGreaterThan($first, $refreshed, 'Computing again reads the line as it is now.');

        $this->paidOrder($customer, '2000000.000000', 1, '0');
        $this->assertPageRenders(self::CATEGORY_URL);
        self::assertGreaterThan($refreshed, $this->revenue(), 'A new order is counted at once.');
    }

    private function revenue(): int
    {
        return (int) preg_replace('/\D/', '', $this->client->getCrawler()->filter('[data-testid="report-sales-revenue"]')->text());
    }

    private function orderCount(): int
    {
        return (int) preg_replace('/\D/', '', $this->client->getCrawler()->filter('[data-testid="report-sales-orders"]')->text());
    }

    private function customer(string $lastname): Customer
    {
        return $this->factory->customer($this->factory->customerTitle(), ['lastname' => $lastname]);
    }

    private function paidOrder(Customer $customer, string $price, int $quantity, string $postage): void
    {
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => $postage]);
        $this->factory->orderProduct($order, ['price' => $price, 'quantity' => $quantity]);
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}

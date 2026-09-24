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

use BackOfficeDefaultTwigBundle\Repository\CartRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The "Reports > Conversion" screen: the checkout funnel of the period, the
 * searches without result for an administrator allowed on the catalog, and the
 * refusal served to an administrator who cannot see the orders.
 */
final class ConversionReportScreenTest extends WebIntegrationTestCase
{
    private const URL = '/admin/reports/conversion';

    private const STEP_KEYS = [
        'carts_created',
        'carts_with_items',
        'carts_with_delivery',
        'carts_with_payment',
        'orders_created',
        'orders_paid',
    ];

    private ?AdminSessionInjector $injector = null;

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

        // Built without createFixtureFactory(): that helper pushes a synthetic
        // request when the stack is empty, and it would then be the "main"
        // request the SecurityContext reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testTheFunnelIsServedToAFullAdministrator(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL);

        $html = $this->html();
        self::assertStringContainsString('data-testid="report-funnel-table"', $html);
        self::assertStringContainsString('data-testid="report-conversion-rate"', $html);
        self::assertStringContainsString('data-testid="report-conversion-formula"', $html);
        self::assertSame(6, preg_match_all('/<tr[^>]*data-testid="report-step-[a-z_]+"/', $html));

        $positions = [];
        foreach (self::STEP_KEYS as $key) {
            $position = strpos($html, 'data-testid="report-step-'.$key.'"');
            self::assertNotFalse($position, \sprintf('The "%s" step must be listed.', $key));
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The steps are listed in the order of the funnel.');

        self::assertStringContainsString('data-testid="report-tab-search"', $html, 'A full administrator sees the searches tab.');
        self::assertStringContainsString('data-testid="report-export-link"', $html, 'A full administrator is offered the export.');
    }

    public function testThePeriodPillOfTheRequestedPresetIsActive(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL.'?period=7days');

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-period-7days"]')->attr('class'));
        self::assertStringNotContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-period-30days"]')->attr('class'));
        self::assertCount(1, $crawler->filter('[data-testid="report-range"]'), 'The dates the figures were computed on are on screen.');
    }

    public function testTodayIsCoveredByTheCartsWithoutANotice(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL.'?period=today');

        self::assertStringNotContainsString('report-coverage-notice', $this->html());
    }

    public function testAYearCutByThePurgeSaysSoAndShowsTheCoveredDates(): void
    {
        if (null !== (new CartRepository())->oldestCartCreatedAt()) {
            self::markTestSkipped('The test database already holds carts: the oldest one is not controlled by this test.');
        }

        $this->loginAs($this->factory->admin());
        $this->factory->cart();

        $this->assertPageRenders(self::URL.'?period=year');

        $crawler = $this->client->getCrawler();
        $today = (new \DateTimeImmutable())->format('d/m/Y');
        self::assertCount(1, $crawler->filter('[data-testid="report-coverage-notice"]'));
        self::assertStringStartsWith($today, trim($crawler->filter('[data-testid="report-range"]')->text()));
    }

    public function testAnOrderOnlyAdministratorGetsTheFunnelWithoutTheSearchesNorTheExport(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
        ]));

        $this->assertPageRenders(self::URL.'?current_tab=search');

        $html = $this->html();
        self::assertStringContainsString('data-testid="report-funnel-table"', $html);
        self::assertStringNotContainsString('report-tab-search', $html, 'The search log is catalog data: it follows the product permission.');
        self::assertStringNotContainsString('data-testid="report-search-log"', $html);
        self::assertStringNotContainsString('report-export-link', $html, 'The export follows the export permission.');
    }

    public function testAnAdministratorAllowedOnTheCatalogGetsTheSearchesTab(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
            AdminResources::PRODUCT => [AccessManager::VIEW],
        ]));

        $this->assertPageRenders(self::URL.'?current_tab=search');

        $html = $this->html();
        self::assertStringContainsString('data-testid="report-tab-search"', $html);
        self::assertStringContainsString('data-testid="report-search-log"', $html);
        self::assertStringContainsString(
            'active',
            (string) $this->client->getCrawler()->filter('[data-testid="report-tab-search"]')->attr('class'),
            'The requested tab is the one opened.',
        );
    }

    public function testAnAdministratorWhoCannotSeeTheOrdersIsRefused(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::CUSTOMER => [AccessManager::VIEW],
        ]));

        $this->client->request('GET', self::URL);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheSideNavigationOffersTheReportsToAnOrderAdministrator(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
        ]));

        $this->assertPageRenders(self::URL);

        $link = $this->client->getCrawler()->filter('[data-testid="bo-nav-reports"] a[href$="'.self::URL.'"]');
        self::assertCount(1, $link, 'The reports group links to the conversion screen.');
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

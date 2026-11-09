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

use BackOfficeDefaultTwigBundle\Service\Report\SalesReportsContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the sales reports the back office stays sound: no menu entry, and the
 * routes answer a message instead of a 500. The core is played absent by naming another class
 * for the calculator, since the local core carries it.
 */
final class SalesReportWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        SalesReportsContext::pretendTheCalculatorIs('Thelia\Domain\Report\Sales\ClassThatDoesNotExist');

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        SalesReportsContext::pretendTheCalculatorIs(null);

        // Null when the case was skipped before the session was set up.
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheGuardReadsTheClassItIsGiven(): void
    {
        self::assertFalse(SalesReportsContext::coreHasSalesReports());
    }

    public function testTheMenuDoesNotOfferTheScreenButKeepsTheOthers(): void
    {
        $this->assertPageRenders('/admin/reports/conversion');

        $crawler = $this->client->getCrawler();
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-sales-report"]'));
        self::assertCount(0, $crawler->filter('a[href*="/admin/reports/sales"]'));
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-reports"] a[href$="/admin/reports/conversion"]'));
    }

    public function testEveryTabAnswersTheMessageWithoutAnError(): void
    {
        foreach (['category', 'product', 'customer'] as $tab) {
            $this->assertPageRenders('/admin/reports/sales/'.$tab);

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $tab);
            $crawler = $this->client->getCrawler();
            self::assertCount(1, $crawler->filter('[data-testid="report-sales-unavailable"]'), $tab);
            self::assertStringContainsString('more recent version of Thelia', $crawler->filter('[data-testid="report-sales-unavailable"]')->text());
            self::assertCount(0, $crawler->filter('[data-testid="report-sales-revenue"]'));
        }
    }
}

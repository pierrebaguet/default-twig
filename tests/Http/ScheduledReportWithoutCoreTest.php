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

use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportsContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ReportMailing;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the scheduled reports the back office is left as it was: no menu
 * entry, no link from the export list, and no route that answers.
 */
final class ScheduledReportWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        // Read on the model itself, not on the guard under test.
        if (class_exists(ReportMailing::class)) {
            self::markTestSkipped('The core carries the scheduled reports: ScheduledReportScreenTest covers them.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        // Null when the case was skipped before the session was set up.
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheGuardReadsTheCore(): void
    {
        self::assertFalse(ScheduledReportsContext::coreHasScheduledReports());
    }

    public function testTheMenuAndTheExportListDoNotOfferTheScreen(): void
    {
        $crawler = $this->client->request('GET', '/admin/export');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="export-scheduled-reports-link"]'));
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-scheduled-reports"]'));
        self::assertCount(0, $crawler->filter('a[href*="/admin/scheduled-reports"]'));
    }

    public function testEveryRouteAnswersNotFound(): void
    {
        foreach (['GET' => ['/admin/scheduled-reports', '/admin/scheduled-reports/new', '/admin/scheduled-reports/1']] as $method => $urls) {
            foreach ($urls as $url) {
                $this->client->request($method, $url);
                self::assertResponseStatusCodeSame(404, $url);
            }
        }

        foreach (['/admin/scheduled-reports/create', '/admin/scheduled-reports/1', '/admin/scheduled-reports/1/toggle', '/admin/scheduled-reports/delete', '/admin/scheduled-reports/send', '/admin/scheduled-reports/settings', '/admin/scheduled-reports/web-scheduler'] as $url) {
            $this->client->request('POST', $url, ['_token' => 'x']);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }
}

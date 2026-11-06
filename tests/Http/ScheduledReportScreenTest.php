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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ReportMailing;
use Thelia\Model\ReportMailingQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tests\Support\Mailer\CapturingMailerFactory;

/**
 * Tools > Export > Scheduled reports: the mailings the shop sends by itself, who may be written
 * to, the manual send, the two settings and the panel that tells how the schedule gets run.
 */
final class ScheduledReportScreenTest extends WebIntegrationTestCase
{
    private const FORM = 'thelia_scheduled_report';

    private ?AdminSessionInjector $injector = null;

    private ?Admin $admin = null;

    private ?FixtureFactory $factory = null;

    private ?int $webSchedulerActivation = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(ReportMailing::class)) {
            self::markTestSkipped('The core predates the scheduled reports: ScheduledReportWithoutCoreTest covers it.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->admin = $this->factory->admin();
        $this->admin->eraseCredentials();
        $this->injector->setAdmin($this->admin);
    }

    protected function tearDown(): void
    {
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        if (null !== $this->webSchedulerActivation) {
            ModuleQuery::create()->filterByCode('WebScheduler')->update(['Activate' => $this->webSchedulerActivation]);
        }

        parent::tearDown();
    }

    public function testTheListShowsTheReportsAndTheTools(): void
    {
        $mailing = $this->mailing('Morning figures', ['one@example.com', 'two@example.com']);

        $crawler = $this->client->request('GET', '/admin/scheduled-reports');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="scheduled-reports-page"]'));
        self::assertCount(1, $crawler->filter('[data-testid="scheduled-report-create-button"]'));
        self::assertCount(1, $crawler->filter('[data-testid="scheduled-reports-settings"]'));
        self::assertCount(1, $crawler->filter('[data-testid="scheduled-reports-scheduling"]'));
        self::assertStringContainsString('Morning figures', $this->html());
        self::assertStringContainsString('Every day from 07:00', $this->html());
        self::assertSame('Never', trim($crawler->filter('[data-testid="datatable-scheduled-reports-cell-last_sent"]')->first()->text('')));
        self::assertCount(1, $crawler->filter('[data-testid="datatable-scheduled-reports-row"]'));
        self::assertSame('2', trim($crawler->filter('[data-testid="datatable-scheduled-reports-cell-recipients"]')->first()->text('')), 'two recipients');
        self::assertNotNull($mailing->getId());
    }

    public function testTheScheduleIsToldInWordsInFrench(): void
    {
        $this->mailing('Weekly words', ['one@example.com'])->setFrequency('weekly')->setDayOfWeek(1)->save($this->getPropelConnection());
        $this->mailing('Monthly words', ['one@example.com'])->setFrequency('monthly')->setDayOfMonth(3)->save($this->getPropelConnection());

        $this->client->request('GET', '/admin/scheduled-reports?lang=fr');

        self::assertStringContainsString('Chaque lundi dès 07:00', $this->html());
        self::assertStringContainsString('Le 3 de chaque mois, dès 07:00', $this->html());
    }

    public function testTheMenuAndTheExportListLeadToTheScreen(): void
    {
        $crawler = $this->client->request('GET', '/admin/export');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="export-scheduled-reports-link"][href$="/admin/scheduled-reports"]'));
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-scheduled-reports"][href$="/admin/scheduled-reports"]'));
    }

    public function testAReportIsCreatedForAnAdministrator(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        self::assertResponseIsSuccessful();

        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Daily orders', 'administrators' => [(string) $this->admin?->getEmail()]]));

        self::assertResponseRedirects('/admin/scheduled-reports');
        $mailing = ReportMailingQuery::create()->findOneByTitle('Daily orders');
        self::assertNotNull($mailing);
        self::assertSame([$this->admin?->getEmail()], $mailing->getRecipientList());
        self::assertSame('daily', $mailing->getFrequency());
        self::assertNull($mailing->getDayOfWeek());
        self::assertSame(1, $mailing->getEnabled());
    }

    public function testAWeeklyReportKeepsItsDay(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Weekly', 'frequency' => 'weekly', 'day_of_week' => '3', 'administrators' => [(string) $this->admin?->getEmail()]]));

        $mailing = ReportMailingQuery::create()->findOneByTitle('Weekly');
        self::assertNotNull($mailing);
        self::assertSame(3, $mailing->getDayOfWeek());
        self::assertNull($mailing->getDayOfMonth());
    }

    public function testAnOtherAddressWithoutTheConfirmationIsRefused(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Outsider', 'other_recipients' => "partner@example.com\n"]));

        self::assertResponseIsSuccessful();
        self::assertNull(ReportMailingQuery::create()->findOneByTitle('Outsider'));
        self::assertStringContainsString('Confirm that these addresses may receive the sales figures of the shop.', $this->html());
    }

    public function testAnOtherAddressWithTheConfirmationIsSaved(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values([
            'title' => 'Partner report',
            'other_recipients' => "partner@example.com\nsecond@example.com",
            'confirm_external' => '1',
        ]));

        self::assertResponseRedirects('/admin/scheduled-reports');
        $mailing = ReportMailingQuery::create()->findOneByTitle('Partner report');
        self::assertNotNull($mailing);
        self::assertSame(['partner@example.com', 'second@example.com'], $mailing->getRecipientList());
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Broken', 'other_recipients' => 'not an address', 'confirm_external' => '1']));

        self::assertResponseIsSuccessful();
        self::assertNull(ReportMailingQuery::create()->findOneByTitle('Broken'));
        self::assertStringContainsString('is not a valid e-mail address', $this->html());
    }

    public function testAReportWithoutRecipientIsRefused(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Nobody']));

        self::assertNull(ReportMailingQuery::create()->findOneByTitle('Nobody'));
        self::assertStringContainsString('Choose at least one recipient.', $this->html());
    }

    public function testTheAuditLogNeverHoldsTheRecipients(): void
    {
        $this->client->request('GET', '/admin/scheduled-reports/new');
        $this->post('/admin/scheduled-reports/create', $this->values(['title' => 'Logged', 'other_recipients' => 'secret-person@example.com', 'confirm_external' => '1']));

        $logs = \Thelia\Model\AdminLogQuery::create()->filterByResource('admin.export')->find();
        $messages = implode("\n", array_map(static fn ($log): string => (string) $log->getMessage(), iterator_to_array($logs)));

        self::assertStringContainsString('Scheduled report Logged saved', $messages);
        self::assertStringNotContainsString('secret-person@example.com', $messages);
    }

    public function testAReportIsEditedAndKeepsItsOtherAddresses(): void
    {
        $mailing = $this->mailing('Editable', ['partner@example.com']);

        $crawler = $this->client->request('GET', '/admin/scheduled-reports/'.$mailing->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('partner@example.com', $crawler->filter('[data-testid="report-other-recipients"]')->text(''));

        $this->post('/admin/scheduled-reports/'.$mailing->getId(), $this->values(['title' => 'Edited', 'other_recipients' => 'partner@example.com', 'confirm_external' => '1']));

        self::assertSame('Edited', ReportMailingQuery::create()->findPk($mailing->getId())?->getTitle());
    }

    public function testTheEnabledSwitchTurnsTheReportOff(): void
    {
        $mailing = $this->mailing('Switch', ['one@example.com']);
        $token = $this->token();

        $this->client->request('POST', '/admin/scheduled-reports/'.$mailing->getId().'/toggle', ['_token' => $token]);

        self::assertSame(0, ReportMailingQuery::create()->findPk($mailing->getId())?->getEnabled());
    }

    public function testADeletionWithoutItsTokenKeepsTheReport(): void
    {
        $mailing = $this->mailing('Keep me', ['one@example.com']);

        $this->client->request('POST', '/admin/scheduled-reports/delete', ['report_id' => $mailing->getId()]);

        self::assertNotNull(ReportMailingQuery::create()->findPk($mailing->getId()));
    }

    public function testADeletionWithItsTokenRemovesTheReport(): void
    {
        $mailing = $this->mailing('Drop me', ['one@example.com']);
        $token = $this->token();

        $this->client->request('POST', '/admin/scheduled-reports/delete', ['report_id' => $mailing->getId(), '_token' => $token]);

        self::assertNull(ReportMailingQuery::create()->findPk($mailing->getId()));
    }

    public function testSendNowSendsTheReportAndSaysToWhom(): void
    {
        $mailing = $this->mailing('Now', ['one@example.com', 'two@example.com']);
        // Before the first request: the service is built by then.
        $factory = $this->captureMail();
        $token = $this->token();
        // After the first request: a config written before it loses the session.
        ConfigQuery::write('store_email', 'shop@example.com');
        ConfigQuery::resetCache();

        $this->client->request('POST', '/admin/scheduled-reports/send', ['report_id' => $mailing->getId(), '_token' => $token]);
        $this->client->followRedirect();

        self::assertSame(2, \count($factory->renderedWith));
        self::assertNotNull(ReportMailingQuery::create()->findPk($mailing->getId())?->getLastSentAt(), 'the list shows when it went out');
        self::assertStringContainsString('Report sent to 2, failed for 0, rejected for 0.', $this->flash());
    }

    public function testSendNowIsRefusedWhileAScheduledRunIsSendingTheSameReport(): void
    {
        $mailing = $this->mailing('Busy', ['one@example.com']);
        $factory = $this->captureMail();
        $token = $this->token();
        ConfigQuery::write('store_email', 'shop@example.com');
        ConfigQuery::resetCache();
        // The lock report:send-due holds while it sends this mailing.
        $held = $this->getService(LockFactory::class)->createLock('report_mailing.'.$mailing->getId());
        self::assertTrue($held->acquire());

        try {
            $this->client->request('POST', '/admin/scheduled-reports/send', ['report_id' => $mailing->getId(), '_token' => $token]);
            $this->client->followRedirect();
        } finally {
            $held->release();
        }

        self::assertSame([], $factory->renderedWith);
        self::assertNull(ReportMailingQuery::create()->findPk($mailing->getId())?->getLastSentAt());
        self::assertStringContainsString('The report is being sent by a scheduled run', $this->flash());
    }

    public function testSendNowSaysWhenTheServerCannotSend(): void
    {
        $mailing = $this->mailing('Cannot', ['one@example.com']);
        $factory = $this->captureMail();
        $token = $this->token();
        // The test shop has no sender address: the core refuses before any mail.
        ConfigQuery::write('store_email', '');
        ConfigQuery::resetCache();

        $this->client->request('POST', '/admin/scheduled-reports/send', ['report_id' => $mailing->getId(), '_token' => $token]);
        $crawler = $this->client->followRedirect();

        self::assertSame([], $factory->renderedWith);
        self::assertCount(1, $crawler->filter('.alert-danger'));
        self::assertStringNotContainsString('Report sent to', $this->html());
    }

    public function testEveryWriteWithoutItsTokenChangesNothing(): void
    {
        $mailing = $this->mailing('Forged', ['one@example.com']);
        $factory = $this->captureMail();
        $this->client->request('GET', '/admin/scheduled-reports');
        ConfigQuery::write('store_email', 'shop@example.com');
        ConfigQuery::write('report_mail_attachment_max_mb', '5');
        ConfigQuery::resetCache();

        $this->client->request('POST', '/admin/scheduled-reports/send', ['report_id' => $mailing->getId()]);
        $this->client->request('POST', '/admin/scheduled-reports/'.$mailing->getId().'/toggle');
        $this->client->request('POST', '/admin/scheduled-reports/settings', ['attachment_max_mb' => '9', 'link_lifetime_days' => '9']);
        $this->client->request('POST', '/admin/scheduled-reports/create', [self::FORM => $this->values(['title' => 'No token', 'administrators' => [(string) $this->admin?->getEmail()]])]);

        self::assertSame([], $factory->renderedWith);
        self::assertSame(1, ReportMailingQuery::create()->findPk($mailing->getId())?->getEnabled());
        ConfigQuery::resetCache();
        self::assertSame('5', ConfigQuery::read('report_mail_attachment_max_mb'));
        self::assertNull(ReportMailingQuery::create()->findOneByTitle('No token'));
    }

    public function testTheSettingsAreSavedAndRefusedOutOfRange(): void
    {
        $token = $this->token();

        $this->client->request('POST', '/admin/scheduled-reports/settings', ['_token' => $token, 'attachment_max_mb' => '0', 'link_lifetime_days' => '7']);
        ConfigQuery::resetCache();
        self::assertSame('0', ConfigQuery::read('report_mail_attachment_max_mb'));
        self::assertSame('7', ConfigQuery::read('report_mail_link_lifetime_days'));

        $this->client->request('POST', '/admin/scheduled-reports/settings', ['_token' => $token, 'attachment_max_mb' => '8', 'link_lifetime_days' => '15']);
        ConfigQuery::resetCache();
        self::assertSame('0', ConfigQuery::read('report_mail_attachment_max_mb'), 'nothing is written when one value is out of range');
        self::assertSame('7', ConfigQuery::read('report_mail_link_lifetime_days'));
    }

    public function testThePanelShowsTheExpressionOfTheShopSchedule(): void
    {
        $crawler = $this->client->request('GET', '/admin/scheduled-reports');

        self::assertSame('*/15 * * * *', $crawler->filter('[data-testid="report-schedule-expression"]')->text(''));
        self::assertStringContainsString('messenger:consume scheduler_thelia', $crawler->filter('[data-testid="report-worker-command"]')->text(''));
    }

    public function testTheCronLineIsShownWhenWebSchedulerIsNotActive(): void
    {
        $this->deactivateWebScheduler();

        $crawler = $this->client->request('GET', '/admin/scheduled-reports');

        self::assertCount(1, $crawler->filter('[data-testid="report-cron-line"]'));
        self::assertMatchesRegularExpression("#^\\*/15 \\* \\* \\* \\* cd '[^']+' && php bin/console report:send-due\$#", $crawler->filter('[data-testid="report-cron-line"]')->text(''));
        self::assertCount(0, $crawler->filter('[data-testid="report-prepare-web-scheduler"]'));
    }

    public function testTheWebSchedulerTaskIsPreparedOnce(): void
    {
        $this->requireWebScheduler();
        $taskQuery = '\WebScheduler\Model\WebSchedulerTaskQuery';
        $taskQuery::create()->filterByCommandName('report:send-due')->delete();

        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        self::assertCount(1, $crawler->filter('[data-testid="report-prepare-web-scheduler"]'));
        self::assertCount(0, $crawler->filter('[data-testid="report-cron-line"]'));
        $token = $this->tokenIn($crawler);

        $this->client->request('POST', '/admin/scheduled-reports/web-scheduler', ['_token' => $token]);
        $this->client->request('POST', '/admin/scheduled-reports/web-scheduler', ['_token' => $token]);

        self::assertSame(1, $taskQuery::create()->filterByCommandName('report:send-due')->count());
        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        self::assertCount(0, $crawler->filter('[data-testid="report-prepare-web-scheduler"]'));
        self::assertStringContainsString('/web-scheduler/run/', $crawler->filter('[data-testid="report-web-scheduler-url"]')->text(''));
    }

    public function testPreparingTheWebSchedulerTaskWithoutItsTokenCreatesNothing(): void
    {
        $this->requireWebScheduler();
        $taskQuery = '\WebScheduler\Model\WebSchedulerTaskQuery';
        $taskQuery::create()->filterByCommandName('report:send-due')->delete();

        $this->client->request('POST', '/admin/scheduled-reports/web-scheduler');

        self::assertSame(0, $taskQuery::create()->filterByCommandName('report:send-due')->count());
    }

    public function testAViewOnlyAdministratorSeesTheListButChangesNothing(): void
    {
        $mailing = $this->mailing('Look only', ['one@example.com']);
        $viewer = $this->factory?->restrictedAdmin([AdminResources::EXPORT => [AccessManager::VIEW]]);
        self::assertNotNull($viewer);
        $viewer->eraseCredentials();
        $this->injector?->setAdmin($viewer);
        $factory = $this->captureMail();

        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="scheduled-report-create-button"]'));
        self::assertCount(0, $crawler->filter('[data-testid="report-settings-save"]'));
        $token = $this->tokenIn($crawler);

        $this->client->request('GET', '/admin/scheduled-reports/new');
        self::assertResponseStatusCodeSame(403);

        foreach ([
            '/admin/scheduled-reports/create' => $this->values(['title' => 'Sneaky', 'administrators' => [(string) $this->admin?->getEmail()]]),
            '/admin/scheduled-reports/delete' => ['report_id' => $mailing->getId(), '_token' => $token],
            '/admin/scheduled-reports/send' => ['report_id' => $mailing->getId(), '_token' => $token],
            '/admin/scheduled-reports/'.$mailing->getId().'/toggle' => ['_token' => $token],
            '/admin/scheduled-reports/settings' => ['_token' => $token, 'attachment_max_mb' => '1', 'link_lifetime_days' => '1'],
            '/admin/scheduled-reports/web-scheduler' => ['_token' => $token],
        ] as $url => $payload) {
            $this->client->request('POST', $url, $payload);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::assertNotNull(ReportMailingQuery::create()->findPk($mailing->getId()));
        self::assertNull(ReportMailingQuery::create()->findOneByTitle('Sneaky'));
        self::assertSame(1, ReportMailingQuery::create()->findPk($mailing->getId())?->getEnabled());
        self::assertSame([], $factory->renderedWith, 'nothing was sent');
    }

    public function testAViewOnlyAdministratorDoesNotSeeTheSignedAddressOfTheTask(): void
    {
        $this->requireWebScheduler();
        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        $this->client->request('POST', '/admin/scheduled-reports/web-scheduler', ['_token' => $this->tokenIn($crawler)]);
        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        self::assertCount(1, $crawler->filter('[data-testid="report-web-scheduler-url"]'));
        self::assertCount(1, $crawler->filter('[data-testid="report-web-scheduler-ip-note"]'));

        $viewer = $this->factory?->restrictedAdmin([AdminResources::EXPORT => [AccessManager::VIEW]]);
        self::assertNotNull($viewer);
        $viewer->eraseCredentials();
        $this->injector?->setAdmin($viewer);

        $crawler = $this->client->request('GET', '/admin/scheduled-reports');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="report-web-scheduler-task"]'));
        self::assertCount(0, $crawler->filter('[data-testid="report-web-scheduler-url"]'));
        self::assertStringNotContainsString('/web-scheduler/run/', (string) $this->client->getResponse()->getContent());
    }

    public function testTheExportsWithoutADateRangeAreToldAndMarked(): void
    {
        $crawler = $this->client->request('GET', '/admin/scheduled-reports/new');

        self::assertGreaterThan(0, $crawler->filter('select[name="thelia_scheduled_report[export_ref]"] option[data-date-range="1"]')->count());
        self::assertGreaterThan(0, $crawler->filter('select[name="thelia_scheduled_report[export_ref]"] option[data-date-range="0"]')->count());
        self::assertStringContainsString('The period applies only to the exports that have a date range.', $this->html());
    }

    /**
     * The mail factory of the shop hands the message to the server without the logger of the
     * tests: this one remembers each message it was asked to build.
     */
    private function captureMail(): CapturingMailerFactory
    {
        $factory = new CapturingMailerFactory(
            $this->getService(TemplateHelperInterface::class),
            $this->getService(ParserResolver::class),
            $this->getService(MailerInterface::class),
            self::getContainer()->get('mailer.transports'),
        );
        self::getContainer()->set(MailerFactory::class, $factory);

        return $factory;
    }

    private function deactivateWebScheduler(): void
    {
        $module = ModuleQuery::create()->findOneByCode('WebScheduler');

        if (null !== $module) {
            $this->webSchedulerActivation = (int) $module->getActivate();
            $module->setActivate(0)->save();
        }
    }

    private function requireWebScheduler(): void
    {
        $module = ModuleQuery::create()->filterByCode('WebScheduler')->filterByActivate(BaseModule::IS_ACTIVATED)->findOne();

        if (null === $module || !class_exists('WebScheduler\Service\Task\TaskPersister')) {
            self::markTestSkipped('WebScheduler is not active in the test shop.');
        }
    }

    /**
     * @param list<string> $recipients
     */
    private function mailing(string $title, array $recipients): ReportMailing
    {
        $mailing = (new ReportMailing())
            ->setTitle($title)
            ->setExportRef('thelia.export.orders')
            ->setPeriod('yesterday')
            ->setFrequency('daily')
            ->setSendHour(7)
            ->setLocale('en_US')
            ->setRecipients(implode(',', $recipients))
            ->setEnabled(1);
        $mailing->save($this->getPropelConnection());

        return $mailing;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed> the fields of the form of a valid daily report
     */
    private function values(array $overrides): array
    {
        return $overrides + [
            'title' => 'Report',
            'export_ref' => 'thelia.export.orders',
            'period' => 'yesterday',
            'frequency' => 'daily',
            'day_of_week' => '1',
            'day_of_month' => '1',
            'send_hour' => '7',
            'locale' => 'en_US',
            'enabled' => '1',
        ];
    }

    /**
     * Submits the form with the token of the page the client is on.
     *
     * @param array<string, mixed> $values
     */
    private function post(string $url, array $values): void
    {
        $token = $this->client->getCrawler()->filter('input[name="'.self::FORM.'[_token]"]')->attr('value');
        $this->client->request('POST', $url, [self::FORM => $values + ['_token' => $token]]);
    }

    private function token(): string
    {
        return $this->tokenIn($this->client->request('GET', '/admin/scheduled-reports'));
    }

    private function tokenIn(Crawler $crawler): string
    {
        return (string) $crawler->filter('form[action$="/admin/scheduled-reports/settings"] input[name="_token"]')->attr('value');
    }

    private function flash(): string
    {
        return $this->client->getCrawler()->filter('.alert')->text('');
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}

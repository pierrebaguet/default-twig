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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The export of the sales screen: it runs over the exact days on screen and holds the rows of the screen.
 */
final class SalesReportExportTest extends WebIntegrationTestCase
{
    private const CUSTOMER_URL = '/admin/reports/sales/customer';

    private const CATEGORY_URL = '/admin/reports/sales/category';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    /** @var list<string> */
    private array $files = [];

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

        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheExportOfTheScreenRunsOverTheExactDaysAndHoldsTheRowsOfTheScreen(): void
    {
        $first = $this->customer('Exportfirst'.uniqid());
        $second = $this->customer('Exportsecond'.uniqid());
        $this->paidOrder($first, '7000000.000000', 1, '0');
        $this->paidOrder($second, '6000000.000000', 1, '0');
        $this->loginAs($this->factory->admin());
        $from = (new \DateTimeImmutable('-20 days'))->format('Y-m-d');
        $to = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $crawler = $this->client->request('GET', self::CUSTOMER_URL.'?period=custom&from='.$from.'&to='.$to);
        self::assertResponseIsSuccessful();
        $screenEmails = $crawler->filter('[data-testid="report-customer-email"]')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertContains((string) $first->getEmail(), $screenEmails);

        $form = $crawler->filter('[data-testid="report-export-form"]');
        self::assertCount(1, $form);
        $exportId = (int) basename((string) parse_url((string) $form->attr('action'), \PHP_URL_PATH));
        $export = ExportQuery::create()->findPk($exportId);
        self::assertSame('thelia.export.sales_by_customer', $export?->getRef());

        $fields = [];
        foreach ($form->filter('input[type=hidden]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertSame($from, $fields['range_start']);
        self::assertSame($to, $fields['range_end']);

        $this->client->request('POST', (string) $form->attr('action'), $fields);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $csv = (string) file_get_contents($response->getFile()->getPathname());

        $job = ExportJobQuery::create()->filterByExportId($exportId)->orderById('desc')->findOne();
        self::assertNotNull($job);
        $this->files[] = (string) $job->getFilePath();
        self::assertSame($from.' 00:00:00', $job->getRangeStart()?->format('Y-m-d H:i:s'), 'The first second of the first day.');
        self::assertSame($to.' 23:59:59', $job->getRangeEnd()?->format('Y-m-d H:i:s'), 'The last second of the last day.');

        foreach ($screenEmails as $email) {
            self::assertStringContainsString($email, $csv, 'A customer of the screen is in the file.');
        }
        self::assertSame(\count($screenEmails), substr_count($csv, '@'), 'The file holds the customers of the screen and no other.');
    }

    public function testAnExportOfInvalidDaysIsRefusedBeforeAnyJob(): void
    {
        $this->loginAs($this->factory->admin());
        $crawler = $this->client->request('GET', self::CATEGORY_URL);
        $form = $crawler->filter('[data-testid="report-export-form"]');
        $fields = [];
        foreach ($form->filter('input[type=hidden]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $jobs = ExportJobQuery::create()->count();

        $this->client->request('POST', (string) $form->attr('action'), ['range_start' => '2026-02-30', 'range_end' => '2026-03-01'] + $fields);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame($jobs, ExportJobQuery::create()->count());
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
}

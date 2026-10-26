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

use BackOfficeDefaultTwigBundle\Service\Configuration\VisitorCountryContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The detection of the visitor country, set from the store configuration.
 */
final class VisitorCountryConfigurationTest extends WebIntegrationTestCase
{
    private const MODE = 'visitor_country_mode';
    private const SOURCE = 'visitor_country_source';
    private const HEADER = 'visitor_country_header';
    private const DATABASE = 'visitor_country_database';

    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!VisitorCountryContext::coreHasVisitorCountry()) {
            self::markTestSkipped('The core of this checkout predates the visitor country detection: VisitorCountryWithoutCoreTest covers it.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        // The cache of the config table is static and outlives the transaction rollback.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testThePageShowsTheFourSettingsAndTheHeaderSuggestions(): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="config-store-visitor-country"]'));
        self::assertCount(3, $crawler->filter('select[data-testid="config-store-visitor-country-mode"] option'));
        self::assertCount(2, $crawler->filter('select[data-testid="config-store-visitor-country-source"] option'));
        self::assertCount(1, $crawler->filter('input[data-testid="config-store-visitor-country-header"]'));
        self::assertCount(1, $crawler->filter('input[data-testid="config-store-visitor-country-database"]'));

        $input = $crawler->filter('input[data-testid="config-store-visitor-country-header"]');
        self::assertSame(VisitorCountryContext::HEADER_DATALIST_ID, $input->attr('list'));

        $options = $crawler->filter('datalist#'.VisitorCountryContext::HEADER_DATALIST_ID.' option')
            ->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertSame(['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Vercel-IP-Country', 'X-Country-Code'], $options);
    }

    public function testTheSettingsAreSavedFromTheStoreConfiguration(): void
    {
        $this->submit('suggest', 'header', 'X-Country-Code', '');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('suggest', $this->storedValue(self::MODE));
        self::assertSame('header', $this->storedValue(self::SOURCE));
        self::assertSame('X-Country-Code', $this->storedValue(self::HEADER));

        $this->submit('apply', 'ip_database', 'X-Country-Code', 'GeoLite2-Country.mmdb');

        self::assertSame('apply', $this->storedValue(self::MODE));
        self::assertSame('ip_database', $this->storedValue(self::SOURCE));
        self::assertSame('GeoLite2-Country.mmdb', $this->storedValue(self::DATABASE));
    }

    public function testTheSavedSettingsAreShownAgain(): void
    {
        $this->submit('apply', 'ip_database', 'X-Country-Code', 'GeoLite2-Country.mmdb');

        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertSame(
            'apply',
            $crawler->filter('select[data-testid="config-store-visitor-country-mode"] option[selected]')->attr('value'),
        );
        self::assertSame(
            'ip_database',
            $crawler->filter('select[data-testid="config-store-visitor-country-source"] option[selected]')->attr('value'),
        );
        self::assertSame('X-Country-Code', $crawler->filter('input[data-testid="config-store-visitor-country-header"]')->attr('value'));
        self::assertSame('GeoLite2-Country.mmdb', $crawler->filter('input[data-testid="config-store-visitor-country-database"]')->attr('value'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDatabaseNames(): iterable
    {
        yield 'a path outside the directory' => ['../etc/passwd'];
        yield 'two dots in a row' => ['a..mmdb'];
        yield 'a sub directory' => ['sub/db.mmdb'];
        yield 'a backslash' => ['sub\\db.mmdb'];
    }

    #[DataProvider('invalidDatabaseNames')]
    public function testADatabaseNameThatLeavesTheDirectoryIsRefusedAndNothingIsWritten(string $name): void
    {
        $this->submit('suggest', 'ip_database', 'X-Country-Code', 'GeoLite2-Country.mmdb');
        $this->submit('apply', 'ip_database', 'CF-IPCountry', $name, false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('suggest', $this->storedValue(self::MODE));
        self::assertSame('GeoLite2-Country.mmdb', $this->storedValue(self::DATABASE));
    }

    public function testAHeaderNameWithASpaceIsRefusedAndNothingIsWritten(): void
    {
        $this->submit('suggest', 'header', 'X-Country-Code', '');
        $this->submit('apply', 'header', 'X Country', '', false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('suggest', $this->storedValue(self::MODE));
        self::assertSame('X-Country-Code', $this->storedValue(self::HEADER));
    }

    public function testAHeaderSourceWithoutAHeaderIsRefused(): void
    {
        $this->submit('suggest', 'header', 'X-Country-Code', '');
        $this->submit('apply', 'header', '', '', false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('suggest', $this->storedValue(self::MODE));
    }

    public function testAnIpDatabaseSourceWithoutAFileIsRefusedUnlessTheDetectionIsOff(): void
    {
        $this->submit('suggest', 'header', 'X-Country-Code', '');
        $this->submit('apply', 'ip_database', 'X-Country-Code', '', false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('suggest', $this->storedValue(self::MODE));
        self::assertSame('header', $this->storedValue(self::SOURCE));

        $this->submit('off', 'ip_database', 'X-Country-Code', '');

        self::assertSame('off', $this->storedValue(self::MODE));
        self::assertSame('ip_database', $this->storedValue(self::SOURCE));
    }

    private function submit(string $mode, string $source, string $header, string $database, bool $expectRedirect = true): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
            'thelia_configuration_store[visitor_country_header]' => $header,
            'thelia_configuration_store[visitor_country_database]' => $database,
        ]);

        // A value outside the offered choices is rejected by the browser-kit form itself.
        $form->disableValidation();
        $form->setValues([
            'thelia_configuration_store[visitor_country_mode]' => $mode,
            'thelia_configuration_store[visitor_country_source]' => $source,
        ]);

        $this->client->submit($form);

        if ($expectRedirect) {
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        }
    }

    private function storedValue(string $name): ?string
    {
        ConfigQuery::resetCache();

        return ConfigQuery::create()->findOneByName($name)?->getValue();
    }
}

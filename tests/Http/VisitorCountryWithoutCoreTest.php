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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the visitor country detection the store configuration is left
 * as it was: no card, none of the four settings, and a save that still works.
 */
final class VisitorCountryWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (VisitorCountryContext::coreHasVisitorCountry()) {
            self::markTestSkipped('The core carries the visitor country detection: VisitorCountryConfigurationTest covers it.');
        }

        $injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($injector);
        $this->injector = $injector;

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        // Null when the case was skipped before the session was set up.
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheScreenRendersWithoutTheCardAndTheFourSettings(): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="config-store-form"]'));
        self::assertCount(0, $crawler->filter('[data-testid="config-store-visitor-country"]'));
        self::assertCount(0, $crawler->filter('[name^="thelia_configuration_store[visitor_country_"]'));
        self::assertCount(0, $crawler->filter('datalist'));
    }

    public function testTheScreenIsSavedAndLeavesTheVisitorCountrySettingsAlone(): void
    {
        ConfigQuery::write('visitor_country_mode', 'suggest', false);

        $crawler = $this->client->request('GET', '/admin/configuration/store');
        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
        ]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        ConfigQuery::resetCache();
        self::assertSame('suggest', ConfigQuery::create()->findOneByName('visitor_country_mode')?->getValue());
    }
}

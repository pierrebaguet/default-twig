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

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductDestinationRestrictionContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the destination restrictions the back office is left as it was:
 * no menu entry, no screen, no card on the product sheet, and no route that answers.
 */
final class DestinationRestrictionWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (ProductDestinationRestrictionContext::coreHasDestinationRestrictions()) {
            self::markTestSkipped('The core carries the destination restrictions: DestinationRestrictionScreenTest covers them.');
        }

        $injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($injector);
        $this->injector = $injector;
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
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

        parent::tearDown();
    }

    public function testTheConfigurationMenuDoesNotOfferTheScreen(): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="configuration-page"]'));
        self::assertCount(0, $crawler->filter('a[href*="/admin/configuration/destination-restrictions"]'));
    }

    public function testTheScreenAnswersNotFound(): void
    {
        $this->client->request('GET', '/admin/configuration/destination-restrictions');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/configuration/destination-restrictions/1');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/configuration/destination-restrictions/create', ['code' => 'probe', 'title' => 'Probe']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProductSheetShowsNoCardAndCannotAttach(): void
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection());
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency ?? $this->factory->currency());
        $product->setLocale('en_US')->setTitle('Destination restriction probe')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="product-destination-restrictions"]'));

        $this->client->request('POST', '/admin/products/destination-restriction/attach', ['product_id' => $product->getId(), 'restriction_id' => 1]);
        self::assertResponseStatusCodeSame(404);
    }
}

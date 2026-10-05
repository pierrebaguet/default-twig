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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Order\Service\OrderTrackingUrlResolver;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\Order;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The merchant side of the parcel tracking link: the tracking address typed for a
 * carrier, the link it gives on the order page, and the switch of the shipping e-mail.
 */
final class ParcelTrackingBackOfficeTest extends WebIntegrationTestCase
{
    private const string CARRIER_CODE = 'ParcelTrackingBackOfficeCarrier';

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

        if (!class_exists(OrderTrackingUrlResolver::class)) {
            self::markTestSkipped('The core of this checkout predates the parcel tracking link.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        // Both caches are static and outlive the transaction rollback.
        ModuleConfigQuery::resetConfigCache();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheOrderPageLinksTheTrackingNumberToTheCarrierPage(): void
    {
        $order = $this->orderShippedBy($this->carrier('https://carrier.example/track?parcel=%ID%'), '6A 12');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $link = $crawler->filter('[data-testid="order-delivery-tracking-link"]');
        self::assertCount(1, $link);
        self::assertSame('https://carrier.example/track?parcel=6A%2012', $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertStringContainsString('noopener', (string) $link->attr('rel'));
    }

    public function testACarrierWithoutTrackingAddressShowsTheNumberWithoutLink(): void
    {
        $order = $this->orderShippedBy($this->carrier(null), '6A12');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('6A12', $crawler->filter('[data-testid="order-delivery"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="order-delivery-tracking-link"]'));
    }

    public function testTheShippingPageOfACarrierShowsItsTrackingAddress(): void
    {
        $carrier = $this->carrier('https://carrier.example/track?parcel=%ID%');

        $crawler = $this->client->request('GET', '/admin/configuration/shipping_zones/update/'.$carrier->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'https://carrier.example/track?parcel=%ID%',
            $crawler->filter('[data-testid="shipping-zones-tracking-url-input"]')->attr('value'),
        );
    }

    public function testAValidTrackingAddressIsSaved(): void
    {
        $carrier = $this->carrier(null);

        $this->submitTrackingUrl($carrier, '  https://carrier.example/track/%ID%  ');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('https://carrier.example/track/%ID%', $this->storedTemplateOf($carrier));
        $this->client->followRedirect();
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-success"]'));
    }

    /**
     * The template ends up as a link in front of the customer: anything but an http(s)
     * address carrying the marker is refused, and the previous value stays.
     */
    public function testATrackingAddressThatIsNotAWebAddressWithTheMarkerIsRefused(): void
    {
        $carrier = $this->carrier('https://carrier.example/%ID%');

        foreach (['javascript:alert(document.cookie)//%ID%', 'https://carrier.example/track'] as $refused) {
            $this->submitTrackingUrl($carrier, $refused);

            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertSame('https://carrier.example/%ID%', $this->storedTemplateOf($carrier), $refused.' must not be saved.');
            $this->client->followRedirect();
            self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]'), $refused.' must be reported.');
        }
    }

    public function testAnEmptyTrackingAddressRemovesIt(): void
    {
        $carrier = $this->carrier('https://carrier.example/%ID%');

        $this->submitTrackingUrl($carrier, '');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->storedTemplateOf($carrier));
    }

    public function testTheShippingEmailCanBeSwitchedOffAndOnInTheStoreConfiguration(): void
    {
        foreach ([false => '0', true => '1'] as $checked => $expected) {
            $crawler = $this->client->request('GET', '/admin/configuration/store');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());

            $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
                'thelia_configuration_store[store_name]' => 'Test Store',
                'thelia_configuration_store[store_email]' => 'store@test.com',
                'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
                'thelia_configuration_store[store_address1]' => '1 Main Street',
                'thelia_configuration_store[store_zipcode]' => '75001',
                'thelia_configuration_store[store_city]' => 'Paris',
            ]);
            $checkbox = $form['thelia_configuration_store[order_shipped_email_enabled]'];
            $checked ? $checkbox->tick() : $checkbox->untick();

            $this->client->submit($form);

            self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 200 means the form was rejected.');
            self::assertSame($expected, ConfigQuery::read('order_shipped_email_enabled'));
        }
    }

    private function submitTrackingUrl(Module $carrier, string $trackingUrl): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/shipping_zones/update/'.$carrier->getId());
        $form = $crawler->filter('[data-testid="shipping-zones-tracking-url-submit"]')->form(['tracking_url' => $trackingUrl]);

        $this->client->submit($form);
        ModuleConfigQuery::resetConfigCache();
    }

    private function storedTemplateOf(Module $carrier): ?string
    {
        return ModuleConfigQuery::create()->getConfigValue($carrier->getId(), OrderTrackingUrlResolver::TRACKING_URL_CONFIG_KEY);
    }

    private function carrier(?string $trackingUrlTemplate): Module
    {
        $module = new Module();
        $module
            ->setCode(self::CARRIER_CODE)
            ->setType(BaseModule::DELIVERY_MODULE_TYPE)
            ->setActivate(BaseModule::IS_ACTIVATED)
            ->setFullNamespace(self::CARRIER_CODE.'\\'.self::CARRIER_CODE)
            ->setLocale('en_US')
            ->setTitle('Parcel tracking carrier')
            ->save($this->getPropelConnection());

        if (null !== $trackingUrlTemplate) {
            ModuleConfigQuery::create()->setConfigValue($module->getId(), OrderTrackingUrlResolver::TRACKING_URL_CONFIG_KEY, $trackingUrlTemplate);
        }

        return $module;
    }

    private function orderShippedBy(Module $carrier, string $trackingNumber): Order
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()), ['statusCode' => 'sent', 'deliveryModuleCode' => $carrier->getCode()]);
        $order->setDeliveryRef($trackingNumber)->save($this->getPropelConnection());

        return $order;
    }
}

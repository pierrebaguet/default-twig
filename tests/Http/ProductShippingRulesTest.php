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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Product\ProductFacade;
use Thelia\Domain\Shipping\ProductRule\ShippingSurchargeMode;
use Thelia\Model\Area;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\AreaDeliveryModuleQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductExcludedDeliveryModuleQuery;
use Thelia\Model\ProductQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The shipping rules of a product on its sheet: the surcharge, its mode and the
 * free shipping flag saved with the general form, and the carriers the product
 * is kept from, added and removed through their own forms.
 */
final class ProductShippingRulesTest extends WebIntegrationTestCase
{
    private const FORM = 'thelia_product_modification';

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

        // The theme runs on the published core as well, which may predate the shipping
        // rules of a product: the sheet then has nothing of them to test.
        if (!class_exists(ShippingSurchargeMode::class)) {
            self::markTestSkipped('The core does not carry the shipping rules of a product.');
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
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testTheSheetRendersTheShippingRulesOfTheProduct(): void
    {
        $product = $this->product();
        $product->setShippingSurcharge('7.5')->setShippingSurchargeMode(ShippingSurchargeMode::PerUnit->value)->save($this->getPropelConnection());

        $form = $this->sheet($product)->filter('[data-testid="product-edit-save-stay"]')->form();

        self::assertSame(7.5, (float) $form[self::FORM.'[shipping_surcharge]']->getValue());
        self::assertSame('1', $form[self::FORM.'[shipping_surcharge_mode]']->getValue());
        self::assertFalse($form[self::FORM.'[free_shipping]']->hasValue(), 'Free shipping is unticked.');
    }

    public function testSavingTheSheetPersistsTheShippingRules(): void
    {
        $product = $this->product();

        $form = $this->sheet($product)->filter('[data-testid="product-edit-save-stay"]')->form();
        $form[self::FORM.'[shipping_surcharge]'] = '12.5';
        $form[self::FORM.'[shipping_surcharge_mode]'] = (string) ShippingSurchargeMode::PerUnit->value;
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $stored = $this->fresh($product);
        self::assertSame(12.5, (float) $stored->getShippingSurcharge());
        self::assertSame(ShippingSurchargeMode::PerUnit->value, $stored->getShippingSurchargeMode());
        self::assertSame(0, $stored->getFreeShipping());
    }

    public function testFreeShippingKeepsTheSurchargeItDoesNotApplyTo(): void
    {
        $product = $this->product();
        $product->setShippingSurcharge('12.5')->setShippingSurchargeMode(ShippingSurchargeMode::PerUnit->value)->save($this->getPropelConnection());

        $form = $this->sheet($product)->filter('[data-testid="product-edit-save-stay"]')->form();
        $form[self::FORM.'[free_shipping]']->tick();
        $form[self::FORM.'[shipping_surcharge]'] = '99';
        $form[self::FORM.'[shipping_surcharge_mode]'] = (string) ShippingSurchargeMode::PerLine->value;
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $stored = $this->fresh($product);
        self::assertSame(1, $stored->getFreeShipping());
        self::assertSame(12.5, (float) $stored->getShippingSurcharge(), 'The surcharge is ignored, not reset, while shipping is free.');
        self::assertSame(ShippingSurchargeMode::PerUnit->value, $stored->getShippingSurchargeMode());

        $form = $this->sheet($product)->filter('[data-testid="product-edit-save-stay"]')->form();
        self::assertSame(12.5, (float) $form[self::FORM.'[shipping_surcharge]']->getValue(), 'The amount comes back when the box is unticked.');
        $form[self::FORM.'[free_shipping]']->untick();
        $this->client->submit($form);

        $stored = $this->fresh($product);
        self::assertSame(0, $stored->getFreeShipping());
        self::assertSame(12.5, (float) $stored->getShippingSurcharge());
    }

    public function testANegativeSurchargeIsRefused(): void
    {
        $product = $this->product();
        $product->setShippingSurcharge('4')->save($this->getPropelConnection());

        $form = $this->sheet($product)->filter('[data-testid="product-edit-save-stay"]')->form();
        $form[self::FORM.'[shipping_surcharge]'] = '-3';
        $form[self::FORM.'[free_shipping]']->tick();
        $this->client->submit($form);

        $stored = $this->fresh($product);
        self::assertSame(4.0, (float) $stored->getShippingSurcharge());
        self::assertSame(0, $stored->getFreeShipping(), 'Nothing is saved when the form is invalid, even with free shipping ticked.');
    }

    public function testABulkEditKeepsTheShippingRules(): void
    {
        $product = $this->product();
        $product->setShippingSurcharge('6')->setShippingSurchargeMode(ShippingSurchargeMode::PerUnit->value)->setFreeShipping(1)->save($this->getPropelConnection());
        $token = $this->tokenOf($this->sheet($product), 'product-additional-category-form');
        $category = $this->factory->category();

        // The bulk edits rebuild a product update from the stored product.
        $this->client->request('POST', '/admin/products/bulk/edit', [
            '_token' => $token,
            'product_ids' => [$product->getId()],
            'default_category_id' => $category->getId(),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $stored = $this->fresh($product);
        self::assertSame($category->getId(), $stored->getDefaultCategoryId(), 'The bulk edit went through.');
        self::assertSame(6.0, (float) $stored->getShippingSurcharge());
        self::assertSame(ShippingSurchargeMode::PerUnit->value, $stored->getShippingSurchargeMode());
        self::assertSame(1, $stored->getFreeShipping());
    }

    public function testACarrierIsExcludedAndAllowedAgain(): void
    {
        $product = $this->product();
        $carrier = $this->deliveryModule();

        $crawler = $this->sheet($product);
        $options = $crawler->filter('[data-testid="product-carrier-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertContains((string) $carrier->getId(), $options, 'An active carrier is offered.');

        $this->client->submit($crawler->filter('[data-testid="product-carrier-exclude-form"]')->form(['module_id' => (string) $carrier->getId()]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->exclusionsOf($product, $carrier), 'The carrier is excluded.');

        $crawler = $this->sheet($product);
        $title = (string) ($carrier->setLocale('en_US')->getTitle() ?: $carrier->getCode());
        self::assertStringContainsString($title.' Allow again', $crawler->filter('[data-testid="product-excluded-carriers"]')->text(), 'The excluded carrier is listed with its removal action.');
        $options = $crawler->filter('[data-testid="product-carrier-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertNotContains((string) $carrier->getId(), $options, 'An excluded carrier is no longer offered.');

        $this->client->submit($crawler->filter('form[action$="/admin/products/carrier/allow"]')->form(['module_id' => (string) $carrier->getId()]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->exclusionsOf($product, $carrier), 'The carrier is allowed again.');
    }

    public function testAPaymentModuleIsRefusedWithoutAnError(): void
    {
        $product = $this->product();
        $payment = ModuleQuery::create()->filterByType(BaseModule::PAYMENT_MODULE_TYPE)->findOne($this->getPropelConnection());
        self::assertNotNull($payment);
        $token = $this->tokenOf($this->sheet($product), 'product-additional-category-form');

        $this->client->request('POST', '/admin/products/carrier/exclude', [
            '_token' => $token,
            'product_id' => $product->getId(),
            'module_id' => $payment->getId(),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->exclusionsOf($product, $payment), 'A payment module is not a carrier.');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not an active delivery module', $this->client->getResponse()->getContent());
    }

    public function testACarrierCannotBeExcludedWithoutTheToken(): void
    {
        $product = $this->product();
        $carrier = $this->deliveryModule();

        $this->client->request('POST', '/admin/products/carrier/exclude', [
            'product_id' => $product->getId(),
            'module_id' => $carrier->getId(),
        ]);

        self::assertSame(0, $this->exclusionsOf($product, $carrier));
    }

    public function testACarrierCannotBeAllowedAgainWithoutTheToken(): void
    {
        $product = $this->product();
        $carrier = $this->deliveryModule();
        $this->getService(ProductFacade::class)->excludeDeliveryModule((int) $product->getId(), (int) $carrier->getId());

        $this->client->request('POST', '/admin/products/carrier/allow', [
            'product_id' => $product->getId(),
            'module_id' => $carrier->getId(),
        ]);

        self::assertSame(1, $this->exclusionsOf($product, $carrier));
    }

    public function testATokenInTheAddressOnlyChangesNoCarrier(): void
    {
        $product = $this->product();
        $carrier = $this->deliveryModule();
        $token = $this->tokenOf($this->sheet($product), 'product-carrier-exclude-form');
        $query = '?'.http_build_query(['_token' => $token]);
        $body = ['product_id' => $product->getId(), 'module_id' => $carrier->getId()];

        $this->client->request('POST', '/admin/products/carrier/exclude'.$query, $body);
        self::assertSame(0, $this->exclusionsOf($product, $carrier), 'The token is read from the request body only.');

        $this->getService(ProductFacade::class)->excludeDeliveryModule((int) $product->getId(), (int) $carrier->getId());
        $this->client->request('POST', '/admin/products/carrier/allow'.$query, $body);
        self::assertSame(1, $this->exclusionsOf($product, $carrier), 'The token is read from the request body only.');
    }

    public function testAnAdministratorWhoMayOnlyLookCannotExcludeNorAllowACarrier(): void
    {
        $this->assertTheCarrierRoutesAreRefusedTo([AdminResources::PRODUCT => [AccessManager::VIEW]]);
    }

    public function testAnAdministratorWithoutTheCatalogueCannotExcludeNorAllowACarrier(): void
    {
        $this->assertTheCarrierRoutesAreRefusedTo([AdminResources::ORDER => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE]]);
    }

    public function testTheSheetWarnsWhenNoCarrierIsLeft(): void
    {
        $product = $this->product();
        $this->deliveryModule();

        self::assertSame(0, $this->sheet($product)->filter('[data-testid="product-every-carrier-excluded"]')->count(), 'No warning while a carrier is open.');

        $facade = $this->getService(ProductFacade::class);
        foreach (ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->filterByActivate(1)->find($this->getPropelConnection()) as $carrier) {
            $facade->excludeDeliveryModule((int) $product->getId(), (int) $carrier->getId());
        }

        self::assertSame(1, $this->sheet($product)->filter('[data-testid="product-every-carrier-excluded"]')->count(), 'The sheet warns that the product cannot be shipped.');
    }

    public function testACarrierThatServesNoZoneIsNotACarrierLeftForAPhysicalProduct(): void
    {
        $product = $this->product();
        $connection = $this->getPropelConnection();
        $carriers = ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->filterByActivate(1)->find($connection);
        $servingZone = [];
        foreach (AreaDeliveryModuleQuery::create()->find($connection) as $link) {
            $servingZone[(int) $link->getDeliveryModuleId()] = true;
        }

        $carrierWithoutZone = null;
        foreach ($carriers as $carrier) {
            if (!isset($servingZone[(int) $carrier->getId()])) {
                $carrierWithoutZone = $carrier;
            }
        }

        if (null === $carrierWithoutZone || \count($carriers) < 2) {
            self::markTestSkipped('The test shop needs an active carrier that serves no zone, beside another one.');
        }

        // Every carrier able to ship a parcel is excluded; the one left serves no zone.
        $facade = $this->getService(ProductFacade::class);
        foreach ($carriers as $carrier) {
            if ($carrier->getId() !== $carrierWithoutZone->getId()) {
                if (!isset($servingZone[(int) $carrier->getId()])) {
                    $area = (new Area())->setName('Shipping rules probe area');
                    $area->save($connection);
                    (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($carrier->getId())->save($connection);
                }
                $facade->excludeDeliveryModule((int) $product->getId(), (int) $carrier->getId());
            }
        }

        self::assertSame(1, $this->sheet($product)->filter('[data-testid="product-every-carrier-excluded"]')->count(), 'A carrier for downloads alone does not ship this product.');
    }

    /**
     * Both carrier routes, posted with a valid token by an administrator holding only the
     * given rights: refused, and nothing changes.
     *
     * @param array<string, list<string>> $grants
     */
    private function assertTheCarrierRoutesAreRefusedTo(array $grants): void
    {
        $product = $this->product();
        $carrier = $this->deliveryModule();
        $token = $this->tokenOf($this->sheet($product), 'product-carrier-exclude-form');
        $restricted = $this->factory->restrictedAdmin($grants);
        $restricted->eraseCredentials();
        $this->injector?->setAdmin($restricted);
        $body = ['_token' => $token, 'product_id' => $product->getId(), 'module_id' => $carrier->getId()];

        $this->client->request('POST', '/admin/products/carrier/exclude', $body);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Excluding a carrier is refused.');
        self::assertSame(0, $this->exclusionsOf($product, $carrier));

        $this->getService(ProductFacade::class)->excludeDeliveryModule((int) $product->getId(), (int) $carrier->getId());
        $this->client->request('POST', '/admin/products/carrier/allow', $body);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Allowing a carrier again is refused.');
        self::assertSame(1, $this->exclusionsOf($product, $carrier));
    }

    private function product(): Product
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection());

        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency ?? $this->factory->currency());
        // The general form refuses an untitled product.
        $product->setLocale('en_US')->setTitle('Shipping rules probe')->save($this->getPropelConnection());

        return $product;
    }

    private function sheet(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function tokenOf(Crawler $sheet, string $formTestId): string
    {
        $token = $sheet->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();
        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field.', $formTestId));

        return (string) $token->attr('value');
    }

    private function fresh(Product $product): Product
    {
        ProductTableMap::clearInstancePool();
        $fresh = ProductQuery::create()->findPk($product->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function exclusionsOf(Product $product, Module $module): int
    {
        return ProductExcludedDeliveryModuleQuery::create()
            ->filterByProductId($product->getId())
            ->filterByModuleId($module->getId())
            ->count($this->getPropelConnection());
    }

    private function deliveryModule(): Module
    {
        // The facade only excludes a module whose class is a delivery module: a row with
        // a made-up class cannot stand in for one.
        foreach (ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->filterByActivate(1)->find($this->getPropelConnection()) as $module) {
            if (class_exists($module->getFullNamespace()) && $module->isDeliveryModule()) {
                return $module;
            }
        }

        self::markTestSkipped('The test shop has no active delivery module.');
    }
}

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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionSupport;
use BackOfficeDefaultTwigBundle\Tests\Support\BuildsSubscriptions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the subscriptions the back office is left as it was: no menu entry,
 * no plans tab on the product sheet, no section on the customer sheet, no figure on the
 * dashboard, and routes that answer 404.
 *
 * The test runs on a core that has the subscriptions: the theme is handed the answer of a
 * core without them, by a support that looks for a class that does not exist, which is what
 * the published core gives the CI of the theme.
 */
final class SubscriptionWithoutCoreTest extends WebIntegrationTestCase
{
    use BuildsSubscriptions;

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        static::getContainer()->set(
            SubscriptionSupport::class,
            new SubscriptionSupport('Thelia\\Domain\\Subscription\\ClassOfACoreWithoutSubscriptions'),
        );

        $injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($injector);
        $this->injector = $injector;
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin(['locale' => 'en_US']);
        $admin->eraseCredentials();
        $injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheProductSheetHasNoPlansTab(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency());

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=subscription-plans');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="product-tab-subscription-plans"]'));
        self::assertCount(0, $crawler->filter('#tab-subscription-plans'));
        self::assertCount(1, $crawler->filter('[data-testid="product-tab-general"].active'), 'The unknown tab falls back to the general one.');
        self::assertSame(
            ['general', 'attributes', 'pse', 'related', 'images', 'documents', 'seo', 'modules'],
            array_values(array_intersect(
                $crawler->filter('[data-testid^="product-tab-"]')->each(static fn ($tab): string => str_replace('product-tab-', '', (string) $tab->attr('data-testid'))),
                ['general', 'attributes', 'pse', 'related', 'images', 'documents', 'seo', 'subscription-plans', 'modules'],
            )),
            'The tabs are the ones of a core without subscriptions.',
        );
    }

    public function testTheCustomerSheetHasNoSubscriptionsSection(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="customer-subscriptions-section"]'));
    }

    public function testTheDashboardAndTheMenuHaveNoSubscriptionEntry(): void
    {
        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="kpi-mrr"]'));
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-subscription"]'));
        self::assertStringNotContainsString('Monthly recurring revenue', (string) $this->client->getResponse()->getContent());
    }

    public function testEveryRouteOfTheFeatureAnswers404(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency());

        $this->client->request('GET', '/admin/subscriptions');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/subscriptions/1');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/subscriptions/1/cancel', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);

        foreach (['save', 'delete', 'visibility', 'move'] as $action) {
            $this->client->request('POST', '/admin/products/subscription-plans/'.$action, ['product_id' => $product->getId(), 'plan_id' => 1, '_token' => 'x']);
            self::assertResponseStatusCodeSame(404, $action);
        }
    }
}

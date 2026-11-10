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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanPriceQuery;
use Thelia\Model\SubscriptionPlanQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The plans tab of the product sheet: the plans of the product, written in one transaction
 * with a title per language and a price per currency, under the right on the product.
 */
final class ProductSubscriptionPlansTabTest extends WebIntegrationTestCase
{
    use BuildsSubscriptions;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (!(new SubscriptionSupport())->isAvailable()) {
            self::markTestSkipped('The core does not carry the subscriptions.');
        }

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testThePlansAreListedWithTheirPeriodAndPrice(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product, $plan] = $this->aProductWithAPlan('Listed box');

        $crawler = $this->tab($product);

        self::assertCount(1, $crawler->filter('[data-testid="product-tab-subscription-plans"]'));
        $row = $crawler->filter('[data-testid="subscription-plan-row"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Listed box', $row->text());
        self::assertStringContainsString('Every month', $row->text());
        self::assertStringContainsString('9.90', $row->text());
        self::assertSame((string) $plan->getId(), $row->attr('data-plan-id'));
    }

    public function testAPlanIsCreatedWithATitlePerLanguageAndAPricePerCurrency(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        $product = $this->aProduct();
        $defaultId = (int) Currency::getDefaultCurrency()->getId();
        $other = CurrencyQuery::create()->filterById($defaultId, \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();

        $form = $this->tab($product, 'new')->filter('[data-testid="subscription-plan-form"]');
        self::assertCount(1, $form);

        $this->save($product, [
            'period_unit' => 'quarter', 'period_count' => '2', 'trial_days' => '7', 'installment_count' => '8', 'commitment_count' => '3',
            'pausable' => '1', 'visible' => '1',
            'title' => ['en_US' => 'Quarterly box', 'fr_FR' => 'Boîte trimestrielle'],
            'price' => [$defaultId => '19.5', (int) $other?->getId() => '25'],
        ]);

        self::assertResponseRedirects();
        $plan = $this->onlyPlanOf($product);
        self::assertSame(['quarter', 2, 7, 8, 3, 1, 1], [$plan->getPeriodUnit(), $plan->getPeriodCount(), $plan->getTrialDays(), $plan->getInstallmentCount(), $plan->getCommitmentCount(), $plan->getPausable(), $plan->getVisible()]);
        self::assertSame('Quarterly box', $plan->setLocale('en_US')->getTitle());
        self::assertSame('Boîte trimestrielle', $plan->setLocale('fr_FR')->getTitle());
        self::assertSame([$defaultId => 19.5, (int) $other?->getId() => 25.0], $this->pricesOf($plan));
        self::assertSame(1, (int) $plan->getPosition());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-success"]'));
    }

    public function testAPlanWithoutAPriceInTheDefaultCurrencyIsRefusedAndNothingIsWritten(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        $product = $this->aProduct();
        $other = CurrencyQuery::create()->filterById((int) Currency::getDefaultCurrency()->getId(), \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();

        $this->save($product, $this->valid(['price' => [(int) $other?->getId() => '25']]));

        self::assertResponseRedirects();
        self::assertSame(0, SubscriptionPlanQuery::create()->filterByProductId($product->getId())->count());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testAnInvalidFormWritesNothing(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        $product = $this->aProduct();

        foreach ([['period_count' => '0'], ['period_unit' => 'decade'], ['trial_days' => '-3'], ['title' => ['en_US' => '']]] as $broken) {
            $this->save($product, $this->valid($broken));
            self::assertResponseRedirects();
        }

        self::assertSame(0, SubscriptionPlanQuery::create()->filterByProductId($product->getId())->count());
    }

    public function testEditingAPlanReplacesItsPricesAndLeavesItsPosition(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product, $plan] = $this->aProductWithAPlan('Before');
        $defaultId = (int) Currency::getDefaultCurrency()->getId();
        $other = CurrencyQuery::create()->filterById($defaultId, \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();
        $this->save($product, $this->valid(['plan_id' => (string) $plan->getId(), 'price' => [$defaultId => '10', (int) $other?->getId() => '14']]));
        self::assertCount(2, $this->pricesOf($plan));

        $this->save($product, $this->valid(['plan_id' => (string) $plan->getId(), 'title' => ['en_US' => 'After'], 'price' => [$defaultId => '11.25']]));

        $edited = SubscriptionPlanQuery::create()->findPk($plan->getId());
        self::assertSame('After', $edited?->setLocale('en_US')->getTitle());
        self::assertSame([$defaultId => 11.25], $this->pricesOf($plan), 'The prices sent replace the ones the plan had.');
        self::assertSame(1, SubscriptionPlanQuery::create()->filterByProductId($product->getId())->count());
    }

    public function testAPlanIsDeletedWithItsPrices(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product, $plan] = $this->aProductWithAPlan();

        $this->post('/admin/products/subscription-plans/delete', $product, ['plan_id' => $plan->getId()]);

        self::assertResponseRedirects();
        self::assertNull(SubscriptionPlanQuery::create()->findPk($plan->getId()));
        self::assertSame(0, SubscriptionPlanPriceQuery::create()->filterBySubscriptionPlanId($plan->getId())->count());
    }

    public function testAPlanIsTakenOfflineAndMovedUpAndDown(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product, $first] = $this->aProductWithAPlan('First');
        $second = $this->planOf($product, 'Second', 2);
        $third = $this->planOf($product, 'Third', 3);

        $this->post('/admin/products/subscription-plans/visibility', $product, ['plan_id' => $first->getId()]);
        self::assertSame(0, (int) SubscriptionPlanQuery::create()->findPk($first->getId())?->getVisible());
        $this->post('/admin/products/subscription-plans/visibility', $product, ['plan_id' => $first->getId()]);
        self::assertSame(1, (int) SubscriptionPlanQuery::create()->findPk($first->getId())?->getVisible());

        $this->post('/admin/products/subscription-plans/move', $product, ['plan_id' => $third->getId(), 'direction' => 'up']);
        self::assertSame([$first->getId(), $third->getId(), $second->getId()], $this->orderOf($product));
        $this->post('/admin/products/subscription-plans/move', $product, ['plan_id' => $first->getId(), 'direction' => 'down']);
        self::assertSame([$third->getId(), $first->getId(), $second->getId()], $this->orderOf($product));
        $this->post('/admin/products/subscription-plans/move', $product, ['plan_id' => $third->getId(), 'direction' => 'up']);
        self::assertSame([$third->getId(), $first->getId(), $second->getId()], $this->orderOf($product), 'The first one does not move further up.');
    }

    public function testAPlanOfAnotherProductIsNotReachable(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product] = $this->aProductWithAPlan();
        [, $foreign] = $this->aProductWithAPlan('Foreign');

        $this->post('/admin/products/subscription-plans/delete', $product, ['plan_id' => $foreign->getId()]);

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull(SubscriptionPlanQuery::create()->findPk($foreign->getId()));
    }

    public function testAWriteWithoutTheTokenChangesNothing(): void
    {
        $this->actAs($this->factory->admin(['locale' => 'en_US']));
        [$product, $plan] = $this->aProductWithAPlan();

        $this->client->request('POST', '/admin/products/subscription-plans/delete', ['product_id' => $product->getId(), 'plan_id' => $plan->getId(), '_token' => 'forged']);

        self::assertResponseRedirects();
        self::assertNotNull(SubscriptionPlanQuery::create()->findPk($plan->getId()));
    }

    public function testAnAdministratorWhoMayOnlyViewTheProductSeesThePlansWithoutAnyGestureAndCannotWrite(): void
    {
        $this->actAs($this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]], ['locale' => 'en_US']));
        [$product, $plan] = $this->aProductWithAPlan('Viewed');

        $crawler = $this->tab($product);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="subscription-plan-row"]'));
        self::assertCount(0, $crawler->filter('[data-testid="subscription-plan-new"], [data-testid="subscription-plan-edit"], [data-testid="subscription-plan-delete"], [data-testid="subscription-plan-visibility"]'));

        $this->client->request('POST', '/admin/products/subscription-plans/delete', ['product_id' => $product->getId(), 'plan_id' => $plan->getId(), '_token' => $this->tokenOf($crawler)]);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(SubscriptionPlanQuery::create()->findPk($plan->getId()));

        $this->tab($product, 'new');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="subscription-plan-form"]'), 'The form is not offered to a viewer.');
    }

    public function testThePlansFollowTheRightOnTheProductNotTheOneOnTheSubscriptions(): void
    {
        $this->actAs($this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE]], ['locale' => 'en_US']));
        $product = $this->aProduct();

        $this->save($product, $this->valid());

        self::assertResponseRedirects();
        self::assertSame(1, SubscriptionPlanQuery::create()->filterByProductId($product->getId())->count());
    }

    private function aProduct(): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency());
    }

    private function planOf(Product $product, string $title, int $position): SubscriptionPlan
    {
        $plan = (new SubscriptionPlan())->setProductId($product->getId())->setPeriodUnit('month')->setPeriodCount(1)->setPosition($position);
        $plan->setLocale('en_US')->setTitle($title);
        $plan->save($this->getPropelConnection());

        return $plan;
    }

    /**
     * @return list<int>
     */
    private function orderOf(Product $product): array
    {
        return array_map(
            static fn (SubscriptionPlan $plan): int => (int) $plan->getId(),
            SubscriptionPlanQuery::create()->filterByProductId($product->getId())->orderByPosition()->orderById()->find()->getArrayCopy(),
        );
    }

    private function onlyPlanOf(Product $product): SubscriptionPlan
    {
        $plans = SubscriptionPlanQuery::create()->filterByProductId($product->getId())->find();
        self::assertCount(1, $plans);

        return $plans->getFirst();
    }

    /**
     * @return array<int, float>
     */
    private function pricesOf(SubscriptionPlan $plan): array
    {
        $prices = [];
        foreach (SubscriptionPlanPriceQuery::create()->filterBySubscriptionPlanId($plan->getId())->orderByCurrencyId()->find() as $price) {
            $prices[(int) $price->getCurrencyId()] = (float) $price->getPrice();
        }

        return $prices;
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function valid(array $over = []): array
    {
        return $over + [
            'period_unit' => 'month', 'period_count' => '1', 'trial_days' => '0', 'installment_count' => '', 'commitment_count' => '',
            'visible' => '1', 'title' => ['en_US' => 'A plan'], 'price' => [(int) Currency::getDefaultCurrency()->getId() => '9.9'],
        ];
    }

    private function tab(Product $product, ?string $plan = null): Crawler
    {
        return $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=subscription-plans'.(null === $plan ? '' : '&plan='.$plan));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(Product $product, array $fields): void
    {
        $this->post('/admin/products/subscription-plans/save', $product, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $url, Product $product, array $fields): void
    {
        $crawler = $this->tab($product);
        $this->client->request('POST', $url, $fields + ['product_id' => $product->getId(), '_token' => $this->tokenOf($crawler)]);
    }

    private function tokenOf(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
    }

    private function actAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }
}

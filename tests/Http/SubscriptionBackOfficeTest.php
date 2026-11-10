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
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Subscription;
use Thelia\Model\SubscriptionHistoryQuery;
use Thelia\Model\SubscriptionQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The subscription screens of the back office: the list with its filters and counters, the
 * detail with its frozen values, its installments and its history, and the four gestures,
 * each behind its right, its token and the state machine of the core.
 */
final class SubscriptionBackOfficeTest extends WebIntegrationTestCase
{
    use BuildsSubscriptions;

    private const TOKEN = 'tok_SECRET_do_not_render_5c1d';

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

    public function testTheListShowsTheSubscriptionsTheirStateAndTheCountersOfTheShortcutChips(): void
    {
        $this->asSuperAdministrator();
        $before = $this->counters();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->aCustomer('Zelda', 'Listable');
        $active = $this->aSubscriptionRow($customer, $product, $plan);
        $this->aSubscriptionRow($customer, $product, $plan, ['Status' => 'payment_failed']);

        $crawler = $this->client->request('GET', '/admin/subscriptions?q='.$active->getRef());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('[data-testid="datatable-subscriptions-row"]'));
        $row = $crawler->filter('[data-testid="datatable-subscriptions-row"]')->first();
        self::assertStringContainsString($active->getRef(), $row->text());
        self::assertStringContainsString('Zelda Listable', $row->text());
        self::assertStringContainsString('Active', $row->text());
        self::assertStringContainsString('/admin/subscriptions/'.$active->getId(), (string) $row->filter('[data-testid="subscription-ref-link"]')->attr('href'));

        $after = $this->counters();
        self::assertSame($before['active'] + 1, $after['active']);
        self::assertSame($before['payment_failed'] + 1, $after['payment_failed']);
    }

    public function testTheListFiltersOnStatusCustomerPlanAndNextBillingDate(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan('Plan filter A');
        [, $otherPlan] = $this->aProductWithAPlan('Plan filter B');
        $customer = $this->aCustomer('Yvonne', 'Filterable');
        $early = $this->aSubscriptionRow($customer, $product, $plan, ['NextBillingAt' => new \DateTime('2031-03-10 10:00:00')]);
        $late = $this->aSubscriptionRow($customer, $product, $plan, ['Status' => 'suspended', 'NextBillingAt' => new \DateTime('2031-09-10 10:00:00')]);
        $onAnotherPlan = $this->aSubscriptionRow($customer, $product, $otherPlan);

        $refs = fn (string $query): array => $this->refsOf('/admin/subscriptions?customer_id='.$customer->getId().$query);

        self::assertEqualsCanonicalizing([$early->getRef(), $late->getRef(), $onAnotherPlan->getRef()], $refs(''));
        self::assertSame([$late->getRef()], $refs('&status[]=suspended'));
        self::assertSame([$early->getRef()], $refs('&next_from=2031-03-01&next_to=2031-04-01'));
        self::assertSame([$late->getRef()], $refs('&next_from=2031-08-01'));
        self::assertSame([$onAnotherPlan->getRef()], $refs('&plan='.$otherPlan->getId()), 'The plan filter keeps the subscriptions taken on that plan.');
        self::assertEqualsCanonicalizing([$early->getRef(), $late->getRef()], $refs('&plan='.$plan->getId()));
        self::assertSame([], $refs('&status[]=ended'), 'No match: the table is empty.');
        self::assertSame([$early->getRef(), $late->getRef()], $refs('&plan='.$plan->getId().'&order=next_billing_at&direction=asc'));

        $crawler = $this->client->request('GET', '/admin/subscriptions?status[]=suspended&plan='.$plan->getId().'&customer_id='.$customer->getId());
        self::assertCount(3, $crawler->filter('[data-testid^="subscription-active-chip-"]'), 'One removable chip per filter in force.');
        self::assertStringContainsString('Plan filter A', $crawler->filter('[data-testid="subscription-active-chip-plan"]')->text());
        self::assertGreaterThanOrEqual(2, $crawler->filter('[data-testid="subscription-filter-plan"] option')->count(), 'The dropdown lists the plans.');
        self::assertSame((string) $plan->getId(), $crawler->filter('[data-testid="subscription-filter-plan"] option[selected]')->attr('value'));
    }

    public function testTheFreeSearchMatchesTheReferenceTheLastNameTheFirstNameAndTheEmailAndNothingElse(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $unique = 'Qzx'.bin2hex(random_bytes(3));
        $byLastName = $this->aCustomer('Plain', $unique.'son');
        $byFirstName = $this->aCustomer($unique.'ette', 'Other');
        $byEmail = $this->aCustomer('Mail', 'Holder');
        $byEmail->setEmail('someone-'.$unique.'@example.test')->save($this->getPropelConnection());
        $stranger = $this->aCustomer('Nobody', 'Related');
        $rows = [
            'last name' => $this->aSubscriptionRow($byLastName, $product, $plan),
            'first name' => $this->aSubscriptionRow($byFirstName, $product, $plan),
            'email' => $this->aSubscriptionRow($byEmail, $product, $plan),
            'reference' => $this->aSubscriptionRow($stranger, $product, $plan, ['Ref' => 'SUB'.strtoupper($unique)]),
        ];
        $this->aSubscriptionRow($stranger, $product, $plan);

        self::assertEqualsCanonicalizing(
            array_map(static fn (Subscription $s): string => (string) $s->getRef(), array_values($rows)),
            $this->refsOf('/admin/subscriptions?q='.$unique),
            'The unique word is found in each of the four places, and the unrelated subscription is left out.',
        );
        self::assertSame([$rows['reference']->getRef()], $this->refsOf('/admin/subscriptions?q='.$rows['reference']->getRef()));
        self::assertSame([], $this->refsOf('/admin/subscriptions?q='.$unique.'zzz'));

        // The search is one group: it does not let the other filters through.
        self::assertSame([$rows['email']->getRef()], $this->refsOf('/admin/subscriptions?q='.$unique.'&customer_id='.$byEmail->getId()));
        self::assertSame([], $this->refsOf('/admin/subscriptions?q='.$unique.'&status[]=ended'));
        self::assertSame([], $this->refsOf('/admin/subscriptions?q=100%25'), 'A percent sign is a character, not a wildcard.');
    }

    public function testTheDetailShowsTheValuesFrozenOnTheSubscriptionTheMethodAsALabelTheHistoryAndTheInstallments(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan('Frozen box');
        $customer = $this->aCustomer('Wanda', 'Detailed');
        $subscription = $this->aSubscriptionRow($customer, $product, $plan, [
            'CustomerPaymentMethodId' => $this->method($customer)->getId(),
            'Quantity' => 2,
            'UnitPrice' => '12.500000',
            'CommitmentCount' => 3,
        ]);
        $this->aHistoryRow($subscription, null, 'active', 'system', null, 'Created.');
        $this->aHistoryRow($subscription, 'active', 'paused', 'admin', (int) $this->factory->admin()->getId(), 'Paused by hand.');
        $paid = $this->anInstallmentRow($subscription, 1);
        $this->anInstallmentRow($subscription, 2, 'failed', false);

        // The plan changes after the subscription was taken: the subscription keeps its terms.
        $plan->setPeriodUnit('year')->setPeriodCount(5)->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
        self::assertResponseIsSuccessful();
        $page = $crawler->text();

        self::assertStringContainsString($subscription->getRef(), $page);
        self::assertStringContainsString('Wanda Detailed', $page);
        $frozen = (string) preg_replace('/\s+/u', ' ', $crawler->filter('[data-testid="subscription-frozen-values"]')->text());
        self::assertStringContainsString('Every month', $frozen, 'The period is the one copied from the plan.');
        self::assertStringContainsString('12.50', $frozen);
        self::assertStringContainsString('25.00', $frozen, 'The price of an installment is the unit price times the quantity.');
        self::assertStringContainsString('Visa ending 4242', $crawler->filter('[data-testid="subscription-payment-method"]')->text());
        self::assertStringNotContainsString(self::TOKEN, $this->client->getResponse()->getContent() ?: '', 'The token of the method never leaves the model.');
        self::assertCount(2, $crawler->filter('[data-testid="subscription-history-row"]'));
        self::assertStringContainsString('Paused by hand.', $crawler->filter('[data-testid="subscription-history"]')->text());
        self::assertCount(2, $crawler->filter('[data-testid="datatable-subscription-installments-row"]'));
        self::assertCount(1, $crawler->filter('[data-testid="subscription-installment-order-link"]'), 'The installment that has no order has no link.');
        self::assertStringContainsString('/admin/order/update/'.$paid->getOrderId(), (string) $crawler->filter('[data-testid="subscription-installment-order-link"]')->attr('href'));
    }

    public function testTheInstallmentsArePaginated(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Paula', 'Paginated'), $product, $plan);
        for ($number = 1; $number <= 14; ++$number) {
            $this->anInstallmentRow($subscription, $number, 'paid', false);
        }

        $first = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
        self::assertCount(12, $first->filter('[data-testid="datatable-subscription-installments-row"]'));
        self::assertStringContainsString('#14', $first->filter('[data-testid="datatable-subscription-installments-row"]')->first()->text(), 'The latest installment comes first.');

        $second = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId().'?installments_page=2');
        self::assertCount(2, $second->filter('[data-testid="datatable-subscription-installments-row"]'));
    }

    public function testTheButtonsFollowTheStateAndThePlan(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->aCustomer('Bruno', 'Buttons');

        $expected = [
            'active' => [1, ['pause', 'cancel']],
            'payment_failed' => [1, ['reactivate', 'cancel']],
            'suspended' => [1, ['reactivate', 'cancel']],
            'paused' => [1, ['resume', 'cancel']],
            'cancelled' => [1, []],
            'ended' => [1, []],
            'trial' => [1, ['cancel']],
        ];

        foreach ($expected as $status => [$pausable, $buttons]) {
            $subscription = $this->aSubscriptionRow($customer, $product, $plan, ['Status' => $status, 'Pausable' => $pausable]);
            $crawler = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
            self::assertSame($buttons, $this->offeredActions($crawler), 'State '.$status);
        }

        $unpausable = $this->aSubscriptionRow($customer, $product, $plan, ['Pausable' => 0]);
        self::assertSame(['cancel'], $this->offeredActions($this->client->request('GET', '/admin/subscriptions/'.$unpausable->getId())), 'A plan that cannot be paused offers no pause.');
    }

    public function testEachGestureGoesThroughTheStateMachineAndLeavesAHistoryLineWithTheAdministrator(): void
    {
        $admin = $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->aCustomer('Gina', 'Gestures');

        $subscription = $this->aSubscriptionRow($customer, $product, $plan);
        $this->post($subscription, 'pause');
        self::assertResponseRedirects('/admin/subscriptions/'.$subscription->getId());
        self::assertSame('paused', $this->statusOf($subscription));
        $this->post($subscription, 'resume');
        self::assertSame('active', $this->statusOf($subscription));
        $this->post($subscription, 'cancel');
        self::assertSame('cancelled', $this->statusOf($subscription));

        $history = SubscriptionHistoryQuery::create()->filterBySubscriptionId($subscription->getId())->orderById()->find();
        self::assertCount(3, $history);
        foreach ($history as $line) {
            self::assertSame('admin', $line->getActorType());
            self::assertSame($admin->getId(), $line->getActorId());
        }

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-success"]'));
    }

    public function testAnAdministratorReactivatesASuspendedSubscription(): void
    {
        $admin = $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Rita', 'Retry'), $product, $plan, ['Status' => 'suspended']);

        $this->post($subscription, 'reactivate');

        self::assertResponseRedirects('/admin/subscriptions/'.$subscription->getId());
        $line = SubscriptionHistoryQuery::create()->filterBySubscriptionId($subscription->getId())->orderById('desc')->findOne();
        self::assertSame('admin', $line?->getActorType());
        self::assertSame($admin->getId(), $line->getActorId());
        self::assertSame('Retry asked.', $line->getNote());
    }

    public function testARefusedTransitionIsAFlashErrorNeverAServerError(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->aCustomer('Dora', 'Denied');

        $ended = $this->aSubscriptionRow($customer, $product, $plan, ['Status' => 'ended']);
        $this->post($ended, 'cancel');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-danger"]'), 'The refusal of the state machine is shown.');
        self::assertSame('ended', $this->statusOf($ended));
        self::assertSame(0, SubscriptionHistoryQuery::create()->filterBySubscriptionId($ended->getId())->count());

        $unpausable = $this->aSubscriptionRow($customer, $product, $plan, ['Pausable' => 0]);
        $this->post($unpausable, 'pause');
        self::assertResponseRedirects();
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
        self::assertSame('active', $this->statusOf($unpausable));

        $active = $this->aSubscriptionRow($customer, $product, $plan);
        $this->post($active, 'resume');
        self::assertResponseRedirects();
        self::assertSame('active', $this->statusOf($active), 'An active subscription cannot be resumed.');
    }

    public function testAGestureWithoutTheTokenChangesNothing(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Toni', 'Tokenless'), $product, $plan);

        $this->client->request('POST', '/admin/subscriptions/'.$subscription->getId().'/cancel', ['_token' => 'forged']);

        self::assertResponseRedirects();
        self::assertSame('active', $this->statusOf($subscription));
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testAGestureIsAWriteRoute(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Gabi', 'Getter'), $product, $plan);

        $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId().'/cancel');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('active', $this->statusOf($subscription));
    }

    public function testAnAdministratorWhoMayOnlyViewSeesTheScreensWithoutTheButtonsAndCannotPost(): void
    {
        $this->asRestrictedAdministrator([AccessManager::VIEW]);
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Vera', 'Viewer'), $product, $plan);
        $this->anInstallmentRow($subscription, 1);

        $this->client->request('GET', '/admin/subscriptions');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
        self::assertResponseIsSuccessful('No server error on the detail for a viewer.');
        self::assertCount(0, $crawler->filter('[data-testid^="subscription-"][data-testid$="-button"]'));
        self::assertCount(0, $crawler->filter('[id^="subscription-"][id$="-modal"]'));
        self::assertCount(1, $crawler->filter('[data-testid="datatable-subscription-installments-row"]'));

        foreach (['cancel', 'pause', 'resume', 'reactivate'] as $action) {
            $this->client->request('POST', '/admin/subscriptions/'.$subscription->getId().'/'.$action, ['_token' => $this->tokenOf($crawler)]);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertSame('active', $this->statusOf($subscription));
    }

    public function testAnAdministratorWithoutTheRightReachesNeitherTheListNorTheDetail(): void
    {
        $this->asRestrictedAdministrator([]);
        [$product, $plan] = $this->aProductWithAPlan();
        $subscription = $this->aSubscriptionRow($this->aCustomer('Nina', 'Nothing'), $product, $plan);

        $this->client->request('GET', '/admin/subscriptions');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
        self::assertResponseStatusCodeSame(403);
        foreach (['cancel', 'pause', 'resume', 'reactivate'] as $action) {
            $this->client->request('POST', '/admin/subscriptions/'.$subscription->getId().'/'.$action, ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertSame('active', $this->statusOf($subscription));
    }

    public function testTheCustomerSheetListsTheSubscriptionsOfTheCustomerOnly(): void
    {
        $this->asSuperAdministrator();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->aCustomer('Carla', 'Sheet');
        $mine = $this->aSubscriptionRow($customer, $product, $plan, ['Status' => 'paused']);
        $theirs = $this->aSubscriptionRow($this->aCustomer('Dan', 'Elsewhere'), $product, $plan);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertResponseIsSuccessful();

        $section = $crawler->filter('[data-testid="customer-subscriptions-section"]');
        self::assertCount(1, $section);
        self::assertStringContainsString($mine->getRef(), $section->text());
        self::assertStringContainsString('Paused', $section->text());
        self::assertStringNotContainsString($theirs->getRef(), $section->text());
        self::assertSame('1', trim($crawler->filter('[data-testid="customer-subscriptions-section-count"]')->text()));
    }

    public function testTheCustomerSheetOfAViewerWithoutTheRightHasNoSection(): void
    {
        $this->asRestrictedAdministrator([], [\Thelia\Core\Security\Resource\AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$this->aCustomer('Olga', 'Without')->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="customer-subscriptions-section"]'));
    }

    public function testTheMenuAndTheDashboardFigureShowOnlyWithTheRight(): void
    {
        $this->asSuperAdministrator();
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-subscription"]'));
        self::assertCount(1, $crawler->filter('[data-testid="kpi-mrr"]'));

        $this->asRestrictedAdministrator([], [\Thelia\Core\Security\Resource\AdminResources::HOME => [AccessManager::VIEW], \Thelia\Core\Security\Resource\AdminResources::ORDER => [AccessManager::VIEW]]);
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-subscription"]'));
        self::assertCount(0, $crawler->filter('[data-testid="kpi-mrr"]'));
    }

    /**
     * @return list<string>
     */
    private function refsOf(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler->filter('[data-testid="datatable-subscriptions-row"] [data-testid="subscription-ref-link"]')
            ->each(static fn (Crawler $link): string => trim($link->text()));
    }

    /**
     * @return array{active: int, payment_failed: int}
     */
    private function counters(): array
    {
        $crawler = $this->client->request('GET', '/admin/subscriptions');

        return [
            'active' => (int) $crawler->filter('[data-testid="subscription-counter-active"]')->text(),
            'payment_failed' => (int) $crawler->filter('[data-testid="subscription-counter-payment_failed"]')->text(),
        ];
    }

    /**
     * @return list<string>
     */
    private function offeredActions(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="subscription-actions"] button[data-testid]')
            ->each(static fn (Crawler $button): string => (string) preg_replace('/^subscription-(.+)-button$/', '$1', (string) $button->attr('data-testid')));
    }

    private function post(Subscription $subscription, string $action): void
    {
        $crawler = $this->client->request('GET', '/admin/subscriptions/'.$subscription->getId());
        $this->client->request('POST', '/admin/subscriptions/'.$subscription->getId().'/'.$action, ['_token' => $this->tokenOf($crawler)]);
    }

    private function tokenOf(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
    }

    private function statusOf(Subscription $subscription): string
    {
        \Thelia\Model\Map\SubscriptionTableMap::removeInstanceFromPool($subscription);

        return (string) SubscriptionQuery::create()->findPk($subscription->getId())?->getStatus();
    }

    private function aCustomer(string $firstname, string $lastname): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $customer->setFirstname($firstname)->setLastname($lastname)->save($this->getPropelConnection());

        return $customer;
    }

    private function method(Customer $customer): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId((int) $customer->getId())
            ->setPaymentModuleId((int) $this->aModule()->getId())
            ->setToken(self::TOKEN)
            ->setLabel('Visa ending 4242')
            ->setIsDefault(true);
        $method->save($this->getPropelConnection());

        return $method;
    }

    private function asSuperAdministrator(): Admin
    {
        return $this->actAs($this->factory->admin(['locale' => 'en_US']));
    }

    /**
     * @param list<string>                $subscriptionAccesses
     * @param array<string, list<string>> $more
     */
    private function asRestrictedAdministrator(array $subscriptionAccesses, array $more = []): Admin
    {
        $grants = $more;
        if ([] !== $subscriptionAccesses) {
            $grants[SubscriptionSupport::RESOURCE] = $subscriptionAccesses;
        }

        // A profile that grants nothing at all is a profile of its own, not a super administrator.
        return $this->actAs($this->factory->restrictedAdmin($grants, ['locale' => 'en_US']));
    }

    private function actAs(Admin $admin): Admin
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        return $admin;
    }
}

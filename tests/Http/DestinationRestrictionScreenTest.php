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
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\DestinationRestriction;
use Thelia\Model\DestinationRestrictionCountryQuery;
use Thelia\Model\DestinationRestrictionQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductDestinationRestrictionQuery;
use Thelia\Model\State;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The destination restrictions of the shop: the Configuration screen (groups, their
 * wording, their countries and regions, their products) and the card of the product sheet.
 */
final class DestinationRestrictionScreenTest extends WebIntegrationTestCase
{
    private const LIST_URL = '/admin/configuration/destination-restrictions';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
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
        // Absent when setUp() skipped the test on a shop without the Twig back office.
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAGroupIsCreatedWithItsWordingThroughTheScreen(): void
    {
        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="destination-restrictions-page"]'));

        self::assertCount(1, $this->client->request('GET', '/admin/configuration')->filter('a[href$="'.self::LIST_URL.'"]'), 'The Configuration menu offers the screen.');

        $code = $this->code();
        $form = $this->client->request('GET', self::LIST_URL)->filter('[data-testid="destination-restriction-create-form"]')->form([
            'code' => $code,
            'title' => 'Alcohols',
            'reason' => 'Alcohol licence',
            'mode' => (string) DestinationRestriction::MODE_ALLOW,
        ]);
        $this->client->submit($form);

        $group = DestinationRestrictionQuery::create()->findOneByCode($code);
        self::assertNotNull($group, 'The group is saved.');
        self::assertSame(DestinationRestriction::MODE_ALLOW, $group->getMode());
        self::assertSame(1, $group->getActive(), 'The group is applied: its box was ticked.');
        $group->setLocale('en_US');
        self::assertSame('Alcohols', $group->getTitle());
        self::assertSame('Alcohol licence', $group->getReason());

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/configuration/destination-restrictions/'.$group->getId()), 'The merchant lands on the group to list its destinations.');

        $this->client->followRedirect();
        $edit = $this->client->getCrawler();
        self::assertSame('Alcohol licence', $edit->filter('[data-testid="destination-restriction-reason"]')->attr('value'));
        self::assertSame('Alcohols', $edit->filter('[data-testid="destination-restriction-title"]')->attr('value'));

        $list = $this->client->request('GET', self::LIST_URL);
        self::assertStringContainsString($code, $list->filter('[data-testid="destination-restrictions-page"]')->text());
        self::assertStringContainsString('Only listed destinations are served', $list->filter('[data-testid="destination-restrictions-page"]')->text());
    }

    public function testTheWordingIsSavedForTheEditedLanguageOnly(): void
    {
        $group = $this->group();
        $group->setLocale('en_US')->setTitle('Before')->setReason('Before reason')->save();

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $form = $crawler->filter('[data-testid="destination-restriction-edit-form"]')->form([
            'title' => 'After',
            'reason' => 'After reason',
            'mode' => (string) DestinationRestriction::MODE_ALLOW,
        ]);
        $this->client->submit($form);

        $stored = $this->fresh($group);
        $stored->setLocale('en_US');
        self::assertSame('After', $stored->getTitle());
        self::assertSame('After reason', $stored->getReason());
        self::assertSame(DestinationRestriction::MODE_ALLOW, $stored->getMode());
    }

    public function testACountryAndARegionAreAdded(): void
    {
        $group = $this->group();
        [$country, $state] = $this->countryWithRegion();
        [, $foreignState] = $this->countryWithRegion();

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $options = $crawler->filter('[data-testid="destination-restriction-country-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertContains((string) $country->getId(), $options, 'The country is offered.');

        $this->client->submit($crawler->filter('[data-testid="destination-restriction-country-form"]')->form(['country_id' => (string) $country->getId()]));

        self::assertSame(1, $this->destinationCount($group, $country, null), 'The whole country is listed.');

        // The regions of the chosen country come with a round trip, not all of them at once.
        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $this->client->submit($crawler->filter('[data-testid="destination-restriction-region-picker"]')->form(['country_id' => (string) $country->getId()]));
        $picked = $this->client->getCrawler();
        $regions = $picked->filter('[data-testid="destination-restriction-region-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertContains((string) $state->getId(), $regions, 'The region of the country is offered.');
        self::assertNotContains((string) $foreignState->getId(), $regions, 'The region of another country is not.');

        $this->client->submit($picked->filter('[data-testid="destination-restriction-region-form"]')->form(['state_id' => (string) $state->getId()]));

        self::assertSame(1, $this->destinationCount($group, $country, (int) $state->getId()), 'The region is listed.');

        $page = $this->client->request('GET', self::LIST_URL.'/'.$group->getId())->filter('[data-testid="destination-restriction-destinations"]')->text();
        self::assertStringContainsString('Whole country', $page, 'A row without region reads whole country.');
        self::assertStringContainsString('Probe region', $page, 'A region row names the region.');
    }

    public function testARegionOutsideItsCountryIsRefusedWithoutAnError(): void
    {
        $group = $this->group();
        [$country] = $this->countryWithRegion();
        [, $foreignState] = $this->countryWithRegion();
        $token = $this->token();

        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/destination/add', [
            '_token' => $token,
            'country_id' => $country->getId(),
            'state_id' => $foreignState->getId(),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A refusal is a redirect, never a 500.');
        self::assertSame(0, DestinationRestrictionCountryQuery::create()->filterByDestinationRestrictionId($group->getId())->count(), 'Nothing is listed.');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('is not a region of country', (string) $this->client->getResponse()->getContent());
    }

    public function testADestinationIsRemoved(): void
    {
        $group = $this->group();
        [$country] = $this->countryWithRegion();
        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/destination/add', ['_token' => $this->token(), 'country_id' => $country->getId()]);
        $destination = DestinationRestrictionCountryQuery::create()->filterByDestinationRestrictionId($group->getId())->findOne();
        self::assertNotNull($destination);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $this->client->submit($crawler->filter('[data-testid="destination-restriction-destination-'.$destination->getId().'"] form')->form());

        self::assertSame(0, DestinationRestrictionCountryQuery::create()->filterByDestinationRestrictionId($group->getId())->count());
    }

    public function testAProductIsAttachedAndDetachedFromItsSheet(): void
    {
        $product = $this->product();
        $group = $this->group();
        $group->setLocale('en_US')->setTitle('Sheet group')->save();

        $crawler = $this->sheet($product);
        $options = $crawler->filter('[data-testid="product-destination-restriction-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertContains((string) $group->getId(), $options, 'A group the product is not in is offered.');

        $this->client->submit($crawler->filter('[data-testid="product-destination-restriction-attach-form"]')->form(['restriction_id' => (string) $group->getId()]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->linkCount($product, $group), 'The product joined the group.');

        $crawler = $this->sheet($product);
        $row = $crawler->filter('[data-testid="product-destination-restriction-'.$group->getId().'"]');
        self::assertCount(1, $row, 'The group is listed on the sheet.');
        self::assertStringContainsString('Sheet group', $row->text());
        self::assertStringContainsString('Listed destinations are refused', $row->text(), 'The mode is written in words.');
        $options = $crawler->filter('[data-testid="product-destination-restriction-select"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertNotContains((string) $group->getId(), $options, 'A group already attached is no longer offered.');

        // The group screen lists the product, with a link to its sheet.
        $screen = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $productRow = $screen->filter('[data-testid="destination-restriction-product-'.$product->getId().'"]');
        self::assertCount(1, $productRow);
        self::assertStringContainsString((string) $product->getRef(), $productRow->text());
        self::assertSame('/admin/products/update?product_id='.$product->getId(), $productRow->filter('a')->attr('href'));

        $crawler = $this->sheet($product);
        $this->client->submit($crawler->filter('[data-testid="product-destination-restriction-'.$group->getId().'"] form')->form());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->linkCount($product, $group), 'The product left the group.');
    }

    public function testAProductIsRemovedFromTheGroupScreen(): void
    {
        $product = $this->product();
        $group = $this->group();
        $this->client->request('POST', '/admin/products/destination-restriction/attach', ['_token' => $this->token(), 'product_id' => $product->getId(), 'restriction_id' => $group->getId()]);
        self::assertSame(1, $this->linkCount($product, $group));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        $this->client->submit($crawler->filter('[data-testid="destination-restriction-product-'.$product->getId().'"] form')->form());

        self::assertSame(0, $this->linkCount($product, $group));
    }

    public function testAnUnknownGroupIsRefusedOnTheSheetWithoutAnError(): void
    {
        $product = $this->product();

        $this->client->request('POST', '/admin/products/destination-restriction/attach', ['_token' => $this->token(), 'product_id' => $product->getId(), 'restriction_id' => 999999]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unknown destination restriction', (string) $this->client->getResponse()->getContent());
    }

    public function testAGroupIsTurnedOffAndDeleted(): void
    {
        $group = $this->group();
        $token = $this->token();

        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/toggle-active', ['_token' => $token]);
        self::assertSame(0, $this->fresh($group)->getActive(), 'The group is turned off.');

        $this->client->request('POST', self::LIST_URL.'/delete', ['_token' => $token, 'restriction_id' => $group->getId()]);
        self::assertNull(DestinationRestrictionQuery::create()->findPk($group->getId()), 'The group is deleted.');
    }

    public function testNothingIsWrittenWithoutTheToken(): void
    {
        $product = $this->product();
        $group = $this->group();
        [$country] = $this->countryWithRegion();
        $code = $this->code();

        $this->client->request('POST', self::LIST_URL.'/create', ['code' => $code, 'title' => 'No token', 'mode' => '0']);
        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/destination/add', ['country_id' => $country->getId()]);
        $this->client->request('POST', '/admin/products/destination-restriction/attach', ['product_id' => $product->getId(), 'restriction_id' => $group->getId()]);
        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/toggle-active', []);
        $this->client->request('POST', self::LIST_URL.'/delete', ['restriction_id' => $group->getId()]);

        self::assertNull(DestinationRestrictionQuery::create()->findOneByCode($code), 'No group is created.');
        self::assertSame(0, DestinationRestrictionCountryQuery::create()->filterByDestinationRestrictionId($group->getId())->count());
        self::assertSame(0, $this->linkCount($product, $group), 'The product is not attached.');
        self::assertSame(1, $this->fresh($group)->getActive(), 'The group is still on.');
    }

    public function testAnAdministratorWithoutTheResourceCannotOpenTheScreen(): void
    {
        $group = $this->group();
        $this->injector->setAdmin($this->limitedAdmin([AdminResources::GIFT_WRAPPING => [AccessManager::VIEW]]));

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertCount(0, $crawler->filter('[data-testid="destination-restrictions-page"]'));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        self::assertCount(0, $crawler->filter('[data-testid="destination-restriction-edit-page"]'));
    }

    public function testAnAdministratorWhoMayOnlyLookCannotWrite(): void
    {
        $group = $this->group();
        $this->injector->setAdmin($this->limitedAdmin([AdminResources::DESTINATION_RESTRICTION => [AccessManager::VIEW]]));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$group->getId());
        self::assertCount(1, $crawler->filter('[data-testid="destination-restriction-edit-page"]'), 'Looking is allowed.');
        self::assertCount(0, $crawler->filter('[data-testid="destination-restriction-edit-submit"]'), 'No save button without the right to update.');

        $token = $this->token(self::LIST_URL.'/'.$group->getId());
        $code = $this->code();
        $this->client->request('POST', self::LIST_URL.'/create', ['_token' => $token, 'code' => $code, 'title' => 'Read only', 'mode' => '0']);
        $this->client->request('POST', self::LIST_URL.'/'.$group->getId().'/save', ['_token' => $token, 'code' => $group->getCode(), 'title' => 'Changed', 'mode' => '1']);

        self::assertNull(DestinationRestrictionQuery::create()->findOneByCode($code), 'Nothing is created.');
        self::assertSame(DestinationRestriction::MODE_DENY, $this->fresh($group)->getMode(), 'Nothing is changed.');
    }

    public function testAnAdministratorWithoutTheProductRightCannotAttach(): void
    {
        $product = $this->product();
        $group = $this->group();
        // The session token is read while the administrator may still open the sheet.
        $token = $this->token();
        $this->injector->setAdmin($this->limitedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));

        $this->client->request('POST', '/admin/products/destination-restriction/attach', ['_token' => $token, 'product_id' => $product->getId(), 'restriction_id' => $group->getId()]);

        self::assertSame(0, $this->linkCount($product, $group));
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function limitedAdmin(array $grants): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }

    private function code(): string
    {
        return 'probe-'.bin2hex(random_bytes(4));
    }

    private function group(int $mode = DestinationRestriction::MODE_DENY): DestinationRestriction
    {
        $group = (new DestinationRestriction())->setCode($this->code())->setMode($mode)->setActive(1);
        $group->save($this->getPropelConnection());

        return $group;
    }

    private function fresh(DestinationRestriction $group): DestinationRestriction
    {
        \Thelia\Model\Map\DestinationRestrictionTableMap::clearInstancePool();
        $fresh = DestinationRestrictionQuery::create()->findPk($group->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @return array{0: Country, 1: State}
     */
    private function countryWithRegion(): array
    {
        $connection = $this->getPropelConnection();
        // Two countries of one test never share a code, and neither do two runs on a shared base.
        $suffix = random_int(100, 890) + ++$this->sequence;
        $country = $this->factory->country(['isocode' => (string) $suffix, 'isoalpha2' => 'Z'.($suffix % 10), 'isoalpha3' => 'ZZ'.($suffix % 10)]);
        $country->setLocale('en_US')->setTitle('Probe country '.$suffix)->save($connection);

        $state = (new State())->setCountryId($country->getId())->setIsocode('S'.$suffix)->setVisible(1);
        $state->setLocale('en_US')->setTitle('Probe region');
        $state->save($connection);

        return [$country, $state];
    }

    private function destinationCount(DestinationRestriction $group, Country $country, ?int $stateId): int
    {
        return DestinationRestrictionCountryQuery::create()
            ->filterByDestinationRestrictionId($group->getId())
            ->filterByCountryId($country->getId())
            ->filterByStateId($stateId)
            ->count($this->getPropelConnection());
    }

    private function linkCount(Product $product, DestinationRestriction $group): int
    {
        return ProductDestinationRestrictionQuery::create()
            ->filterByProductId($product->getId())
            ->filterByDestinationRestrictionId($group->getId())
            ->count($this->getPropelConnection());
    }

    private function product(): Product
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection());

        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency ?? $this->factory->currency());
        // The general form refuses an untitled product.
        $product->setLocale('en_US')->setTitle('Destination restriction probe')->save($this->getPropelConnection());

        return $product;
    }

    private function sheet(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The token of the session, from a page the administrator can open: the head of every
     * back-office page carries it, and so does any form of the sheet it posts.
     */
    private function token(string $url = '/admin/configuration'): string
    {
        $html = (string) $this->client->request('GET', $url)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches) ?: preg_match('/name="_token" value="([^"]+)"/', $html, $matches), 'The page renders a token.');

        return $matches[1];
    }
}

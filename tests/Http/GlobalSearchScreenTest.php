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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The search page of the back office, as an administrator reaches it from the top bar.
 */
final class GlobalSearchScreenTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private const SEARCH = 'admin.search';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    /** A word no other row of the test shop holds. */
    private string $word;

    /** The administrator whose search budget is spent by a test, given back afterwards. */
    private ?string $limitedLogin = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->word = 'Q'.strtoupper(bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (null !== $this->limitedLogin) {
            $this->limiter()->create($this->limitedLogin)->reset();
        }

        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheExactReferenceOfAProductComesFirstInTheProductsGroup(): void
    {
        $this->product('A'.$this->word);
        $this->product($this->word);
        $this->loginAs($this->factory->admin());

        $page = $this->search($this->word);

        self::assertResponseIsSuccessful();
        self::assertSame($this->word, trim($page->filter('[data-testid="search-group-products"] li')->first()->filter('.text-muted')->last()->text()));
    }

    public function testTheReferenceOfACombinationShowsItsProductAndNamesTheCombination(): void
    {
        $product = $this->product('P'.$this->word, ['title' => 'Chair '.$this->word]);
        $this->factory->productSaleElement($product, ['ref' => $this->word.'-BLUE']);
        $this->loginAs($this->factory->admin());

        $page = $this->search($this->word.'-BLUE');

        $products = $page->filter('[data-testid="search-group-products"]');
        self::assertStringContainsString('Chair '.$this->word, $products->text());
        self::assertStringContainsString($this->word.'-BLUE', $products->filter('[data-testid="search-product-combination"]')->text());
        self::assertCount(1, $page->filter('[data-testid="search-group-combinations"] li'));
    }

    public function testACatalogueAdministratorSeesNoCustomerOrderCouponNorAdministratorAndNoneIsQueried(): void
    {
        $this->factory->customer($this->factory->customerTitle(), ['lastname' => $this->word]);
        $this->factory->coupon(['code' => $this->word]);
        $this->factory->admin(['lastname' => $this->word]);
        $this->product($this->word);
        $this->loginAs($this->factory->restrictedAdmin([
            self::SEARCH => [AccessManager::VIEW],
            AdminResources::PRODUCT => [AccessManager::VIEW],
            AdminResources::CATEGORY => [AccessManager::VIEW],
        ]));

        $statements = $this->recordSqlQueries(function (): void {
            $this->search($this->word);
        });
        $page = $this->client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="search-group-products"]'));
        foreach (['customers', 'orders', 'coupons', 'administrators', 'modules', 'sales'] as $group) {
            self::assertCount(0, $page->filter('[data-testid="search-group-'.$group.'"]'), $group.' is not shown.');
        }
        self::assertCount(0, $page->filter('.alert-danger, .alert-warning'), 'Nothing reads as an error.');
        foreach (['customer', 'order', 'coupon', 'admin'] as $table) {
            self::assertSame(0, self::countSqlQueriesSelectingFrom($statements, $table), $table.' is not searched.');
        }
    }

    public function testAnAdministratorWithoutTheSearchRightIsRefused(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));

        $this->search($this->word);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTitlesAreShownInTheAdministratorLanguageThenInTheShopLanguage(): void
    {
        $translated = $this->product($this->word.'-1', ['title' => 'Chair '.$this->word, 'locale' => 'en_US']);
        $translated->setLocale('fr_FR')->setTitle('Chaise '.$this->word)->save($this->getPropelConnection());
        $this->product($this->word.'-2', ['title' => 'Table '.$this->word, 'locale' => 'en_US']);
        $this->loginAs($this->factory->admin(['locale' => 'fr_FR']));

        // The login screen stores the language of the administrator's profile in the session; the
        // test session skips it, so the language is put there the way the language menu does.
        $products = $this->client->request('GET', '/admin/search?lang=fr_FR&search_term='.$this->word)
            ->filter('[data-testid="search-group-products"]')->text();

        self::assertStringContainsString('Chaise '.$this->word, $products);
        self::assertStringNotContainsString('Chair '.$this->word, $products);
        self::assertStringContainsString('Table '.$this->word, $products, 'No French title: the one of the shop language.');
    }

    public function testATruncatedProductGroupShowsItsTotalAndOpensTheFilteredList(): void
    {
        for ($i = 10; $i < 40; ++$i) {
            $this->product($this->word.'-'.$i);
        }
        $this->loginAs($this->factory->admin());

        $page = $this->search($this->word);

        self::assertSame('25 of 30', trim($page->filter('[data-testid="search-group-products-total"]')->text()));
        $link = (string) $page->filter('[data-testid="search-group-products-list"]')->attr('href');
        self::assertStringContainsString('/admin/products?q='.$this->word, $link);

        $list = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->word.'-39', $list->text());
    }

    public function testATruncatedGroupWithoutAListIsShownInFullPageByPage(): void
    {
        for ($i = 10; $i < 40; ++$i) {
            $this->factory->brand(['title' => $this->word.' brand '.$i]);
        }
        $this->loginAs($this->factory->admin());

        $link = (string) $this->search($this->word)->filter('[data-testid="search-group-brands-all"]')->attr('href');
        self::assertStringContainsString('group=brands', $link);

        $first = $this->client->request('GET', $link);
        self::assertCount(25, $first->filter('[data-testid="search-group-brands"] li'));
        self::assertCount(1, $first->filter('[data-testid="search-pagination"]'));
        self::assertCount(0, $first->filter('[data-testid="search-group-products"]'), 'The other groups are left out.');

        $second = $this->client->request('GET', $link.'&page=2');
        self::assertCount(5, $second->filter('[data-testid="search-group-brands"] li'));
        self::assertStringContainsString($this->word.' brand 39', $second->filter('[data-testid="search-group-brands"]')->text());
    }

    public function testATermWithWildcardsQuotesOrMarkupIsShownAsTyped(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/search?search_term='.rawurlencode('%_"\'<b>'.$this->word));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<b>'.$this->word, $html);
        self::assertStringContainsString('&lt;b&gt;'.$this->word, $html);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="search-no-result"]'));
    }

    public function testATermOfOneCharacterRunsNoSearch(): void
    {
        $this->loginAs($this->factory->admin());

        $statements = $this->recordSqlQueries(function (): void {
            $this->search('a');
        });

        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="search-too-short"]'));
        self::assertSame(0, self::countSqlQueriesSelectingFrom($statements, 'product'));
    }

    public function testTooManySearchesInARowAreRefusedForAMinute(): void
    {
        $admin = $this->factory->admin();
        $this->limitedLogin = (string) $admin->getLogin();
        $this->limiter()->create($this->limitedLogin)->consume(120);
        $this->loginAs($admin);

        $this->search($this->word);

        self::assertResponseStatusCodeSame(429);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="search-rate-limited"]'));
    }

    public function testTheTopBarAnnouncesTheShortcut(): void
    {
        $this->loginAs($this->factory->admin());

        $field = $this->client->request('GET', '/admin/search')->filter('[data-testid="bo-search-form"] input[name="search_term"]');

        self::assertSame('/', $field->attr('aria-keyshortcuts'));
        self::assertSame('bo-search-shortcut', $this->client->getCrawler()->filter('[data-testid="bo-search-form"]')->attr('data-controller'));
    }

    public function testAnAdministratorFoundOpensInTheirEditDialog(): void
    {
        $found = $this->factory->admin(['lastname' => $this->word]);
        $this->loginAs($this->factory->admin());

        $link = (string) $this->search($this->word)->filter('[data-testid="search-group-administrators"] a')->attr('href');
        $list = $this->client->request('GET', $link);

        self::assertSame('#administrator-edit-modal-'.$found->getId(), $list->filter('[data-testid="administrator-edit-requested"]')->attr('data-bo-auto-open-modal-target-value'));
    }

    private function search(string $term): Crawler
    {
        return $this->client->request('GET', '/admin/search?search_term='.rawurlencode($term));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function product(string $ref, array $overrides = []): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency(), ['ref' => $ref] + $overrides);
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        $limiter = $this->getService('limiter.admin_search');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }
}

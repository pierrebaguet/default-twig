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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\Search\SearchGroup;
use BackOfficeDefaultTwigBundle\Service\Search\SearchGroupResult;
use BackOfficeDefaultTwigBundle\Service\Search\SearchTerm;
use Thelia\Domain\Catalog\Product\Identifier\Gtin;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Admin;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * The global search of the back office: what each group finds, in which order, and in which
 * language it reads the titles.
 */
final class GlobalSearchTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private FixtureFactory $factory;

    private SearchRepository $search;

    /** A word no other row of the test shop holds, so each test only meets its own fixtures. */
    private string $word;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->search = new SearchRepository(new OrderAccess(new AdminSaleUnitScope()));
        $this->word = 'Q'.strtoupper(bin2hex(random_bytes(4)));
    }

    public function testAnExactReferenceComesFirstThenAReferenceStartingWithTheTermThenAnyOther(): void
    {
        // Alphabetical order would be the reverse one.
        $this->product('A'.$this->word);
        $this->product($this->word.'-1');
        $this->product($this->word);

        self::assertSame([$this->word, $this->word.'-1', 'A'.$this->word], $this->column($this->find(SearchGroup::Products, $this->word), 'ref'));
    }

    public function testATruncatedGroupKeepsTheBestRowsAndCountsThemAll(): void
    {
        for ($i = 10; $i < 40; ++$i) {
            $this->product('A'.$this->word.'-'.$i);
        }
        $exact = $this->product($this->word);

        $result = $this->find(SearchGroup::Products, $this->word);

        self::assertSame((int) $exact->getId(), (int) $result->rows[0]['id'], 'The exact reference, created last, is not cut off by the limit.');
        self::assertCount(SearchRepository::DISPLAY_LIMIT, $result->rows);
        self::assertSame(31, $result->total);
        self::assertTrue($result->isTruncated());
    }

    public function testTheGroupIsOnlyCountedWhenItDoesNotFitInOnePage(): void
    {
        $this->product($this->word.'-1');
        $this->product($this->word.'-2');

        $statements = $this->recordSqlQueries(fn () => $this->find(SearchGroup::Products, $this->word));

        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'COUNT(*)'))));
    }

    public function testAPageOfAGroupShownOnItsOwnStartsAfterThePreviousOne(): void
    {
        for ($i = 10; $i < 40; ++$i) {
            $this->product($this->word.'-'.$i);
        }

        $second = $this->search->find(SearchGroup::Products, new SearchTerm($this->word), 'en_US', 'en_US', SearchRepository::DISPLAY_LIMIT);

        self::assertSame([$this->word.'-35', $this->word.'-36', $this->word.'-37', $this->word.'-38', $this->word.'-39'], $this->column($second, 'ref'));
        self::assertSame(30, $second->total);
    }

    public function testWildcardsTypedInTheBoxAreLookedForAsCharacters(): void
    {
        $this->product($this->word.'ABC');
        $this->product($this->word.'A_C');

        self::assertSame([$this->word.'A_C'], $this->column($this->find(SearchGroup::Products, $this->word.'A_C'), 'ref'));
        self::assertSame([], $this->find(SearchGroup::Products, $this->word.'%')->rows);
    }

    public function testAQuoteOrABackslashIsSearchedAsTyped(): void
    {
        $this->product($this->word.'-1', ['title' => 'O\'Brien chair \\ '.$this->word]);

        self::assertSame([$this->word.'-1'], $this->column($this->find(SearchGroup::Products, 'O\'Brien chair \\ '.$this->word), 'ref'));
    }

    public function testTheReferenceOfACombinationFindsItsProductAndNamesIt(): void
    {
        $product = $this->product('P'.$this->word);
        $this->factory->productSaleElement($product, ['ref' => $this->word.'-BLUE']);

        $products = $this->find(SearchGroup::Products, $this->word.'-BLUE');
        self::assertSame(['P'.$this->word], $this->column($products, 'ref'));
        self::assertSame($this->word.'-BLUE', $products->rows[0]['combination_ref']);

        $combinations = $this->find(SearchGroup::Combinations, $this->word.'-BLUE');
        self::assertSame([$this->word.'-BLUE'], $this->column($combinations, 'ref'));
        self::assertSame((int) $product->getId(), (int) $combinations->rows[0]['product_id']);
    }

    public function testAProductFoundByItsOwnReferenceNamesNoCombination(): void
    {
        $product = $this->product($this->word);
        $this->factory->productSaleElement($product, ['ref' => $this->word.'-BLUE']);

        self::assertNull($this->find(SearchGroup::Products, $this->word)->rows[0]['combination_ref']);
    }

    public function testACombinationCarryingTheReferenceOfItsProductIsLeftToTheProductsGroup(): void
    {
        // The default combination Product::create() writes carries the product's reference.
        $this->product($this->word);

        self::assertSame([], $this->find(SearchGroup::Combinations, $this->word)->rows);
    }

    public function testAGtinFindsTheProductAndTheCombinationOnlyWhenTypedInFull(): void
    {
        $base = '4'.substr(str_pad((string) random_int(0, 99999999999), 11, '0', \STR_PAD_LEFT), 0, 11);
        $gtin = $base.Gtin::checkDigitOf($base);
        $product = $this->product('P'.$this->word);
        $this->factory->productSaleElement($product, ['ref' => $this->word.'-RED', 'eanCode' => $gtin]);

        $typed = substr($gtin, 0, 1).' '.substr($gtin, 1, 6).' '.substr($gtin, 7);
        self::assertSame(['P'.$this->word], $this->column($this->find(SearchGroup::Products, $typed), 'ref'));
        self::assertSame([$this->word.'-RED'], $this->column($this->find(SearchGroup::Combinations, $typed), 'ref'));
        self::assertSame([], $this->find(SearchGroup::Products, substr($gtin, 2, 7))->rows, 'Part of a GTIN is no GTIN.');
    }

    public function testTitlesAreReadInTheAdministratorLanguageThenInTheShopLanguage(): void
    {
        $translated = $this->product($this->word.'-1', ['title' => 'Chair '.$this->word]);
        $translated->setLocale('fr_FR')->setTitle('Chaise '.$this->word)->save($this->getPropelConnection());
        $untranslated = $this->product($this->word.'-2', ['title' => 'Table '.$this->word]);
        $untranslated->setLocale('fr_FR')->setTitle('')->save($this->getPropelConnection());

        $rows = $this->search->find(SearchGroup::Products, new SearchTerm($this->word), 'fr_FR', 'en_US')->rows;

        self::assertSame(['Chaise '.$this->word, 'Table '.$this->word], array_column($rows, 'label'));
    }

    public function testATitleInAnotherLanguageStillFindsTheRow(): void
    {
        $this->factory->brand(['title' => 'Marque '.$this->word, 'locale' => 'fr_FR']);

        self::assertSame(['Marque '.$this->word], $this->column($this->search->find(SearchGroup::Brands, new SearchTerm($this->word), 'en_US', 'en_US'), 'label'));
    }

    public function testCustomersAreFoundByFullNameAndAnExactEmailComesFirst(): void
    {
        $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Ann', 'lastname' => $this->word, 'email' => 'other-'.strtolower($this->word).'@example.com']);
        $exact = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Bob', 'lastname' => 'Smith', 'email' => strtolower($this->word).'@example.com']);

        self::assertSame((int) $exact->getId(), (int) $this->find(SearchGroup::Customers, strtolower($this->word).'@example.com')->rows[0]['id']);
        self::assertCount(1, $this->find(SearchGroup::Customers, 'Ann '.$this->word)->rows);
    }

    public function testOrdersAreFoundByInvoiceOrTransactionReferenceWithinTheSaleUnitsOfTheAdministrator(): void
    {
        $allowed = $this->factory->saleUnit();
        $other = $this->factory->saleUnit();
        $reached = $this->factory->order();
        $reached->setTransactionRef('INV-'.$this->word.'-1')->setSaleUnitId($allowed->getId())->save($this->getPropelConnection());
        $hidden = $this->factory->order();
        // Found by an alternative before the last one: the restriction must apply to all of them.
        $hidden->setInvoiceRef('INV-'.$this->word.'-2')->setSaleUnitId($other->getId())->save($this->getPropelConnection());

        $restricted = new SearchRepository(new OrderAccess(new class((int) $allowed->getId()) extends AdminSaleUnitScope {
            public function __construct(private readonly int $saleUnitId)
            {
                parent::__construct();
            }

            public function allowedSaleUnitIds(?Admin $admin = null): ?array
            {
                return [$this->saleUnitId];
            }
        }));

        self::assertSame([(int) $reached->getId(), (int) $hidden->getId()], array_map('intval', array_reverse($this->column($this->find(SearchGroup::Orders, 'INV-'.$this->word), 'id'))));
        self::assertSame([(int) $reached->getId()], array_map('intval', $this->column($restricted->find(SearchGroup::Orders, new SearchTerm('INV-'.$this->word), 'en_US', 'en_US'), 'id')));
    }

    public function testCouponsSalesAdministratorsAndModulesAreFound(): void
    {
        $this->factory->coupon(['code' => $this->word.'-10']);
        $sale = $this->factory->sale();
        $sale->setLocale('en_US')->setTitle('Sale '.$this->word)->save($this->getPropelConnection());
        $this->factory->admin(['login' => strtolower($this->word), 'lastname' => 'Doe']);

        self::assertSame([$this->word.'-10'], $this->column($this->find(SearchGroup::Coupons, $this->word), 'code'));
        self::assertSame(['Sale '.$this->word], $this->column($this->find(SearchGroup::Sales, $this->word), 'label'));
        self::assertSame([strtolower($this->word)], $this->column($this->find(SearchGroup::Administrators, $this->word), 'login'));
        self::assertContains('Cheque', $this->column($this->find(SearchGroup::Modules, 'Cheque'), 'code'));
    }

    public function testEveryGroupRunsOneStatementOnATermThatFitsInAPage(): void
    {
        $this->product($this->word);

        $statements = $this->recordSqlQueries(function (): void {
            foreach (SearchGroup::cases() as $group) {
                $this->find($group, $this->word);
            }
        });

        self::assertLessThanOrEqual(\count(SearchGroup::cases()), \count($statements), implode("\n", $statements));
    }

    private function find(SearchGroup $group, string $term): SearchGroupResult
    {
        return $this->search->find($group, new SearchTerm($term), 'en_US', 'en_US');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function product(string $ref, array $overrides = []): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency(), ['ref' => $ref] + $overrides);
    }

    /**
     * @return list<int|string|null>
     */
    private function column(SearchGroupResult $result, string $column): array
    {
        return array_column($result->rows, $column);
    }
}

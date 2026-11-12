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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Search;

use BackOfficeDefaultTwigBundle\Service\Search\SearchTerm;
use BackOfficeDefaultTwigBundle\Service\Search\SqlParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchTermTest extends TestCase
{
    public function testTheTermIsTrimmedAndKeptAsTypedForAnExactMatch(): void
    {
        self::assertSame('PROD-001', (new SearchTerm('  PROD-001 '))->raw);
    }

    public function testTheWildcardsOfLikeAreLookedForAsCharacters(): void
    {
        $term = new SearchTerm('50%_off!');

        self::assertSame('%50!%!_off!!%', $term->contains);
        self::assertSame('50!%!_off!!%', $term->prefix);
    }

    public function testABackslashOrAQuoteIsLeftToTheBoundParameter(): void
    {
        $term = new SearchTerm('O\'Brien \\ "x"');

        self::assertSame('%O\'Brien \\ "x"%', $term->contains);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function gtins(): iterable
    {
        yield 'EAN-13' => ['4006381333931', '4006381333931'];
        yield 'EAN-13 typed in groups' => ['4 006381 333931', '4006381333931'];
        yield 'UPC-A with dashes' => ['0360-0029-1452', '036000291452'];
        yield 'EAN-8' => ['96385074', '96385074'];
        yield 'GTIN-14' => ['10614141000415', '10614141000415'];
        yield 'seven digits' => ['1234567', null];
        yield 'nine digits' => ['123456789', null];
        yield 'a reference' => ['PROD-0001', null];
    }

    #[DataProvider('gtins')]
    public function testATermIsReadAsAGtinOnlyWithTheDigitsOfOne(string $typed, ?string $gtin): void
    {
        self::assertSame($gtin, (new SearchTerm($typed))->gtin);
    }

    public function testTheParametersFollowTheOrderOfThePlaceholders(): void
    {
        $parameters = new SqlParameters();

        $sql = 'a = '.$parameters->add('first').' AND '.$parameters->like('b', '%second%');

        self::assertSame('a = ? AND b LIKE ? ESCAPE \'!\'', $sql);
        self::assertSame(['first', '%second%'], $parameters->values());
    }
}

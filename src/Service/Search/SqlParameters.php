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

namespace BackOfficeDefaultTwigBundle\Service\Search;

/**
 * Collects the values bound to the positional placeholders of an SQL fragment, in the order the
 * placeholders appear: each call returns its placeholder, so a fragment built in one
 * concatenation keeps the values in the order of the text.
 */
final class SqlParameters
{
    /** @var list<string> */
    private array $values = [];

    public function add(string $value): string
    {
        $this->values[] = $value;

        return '?';
    }

    public function like(string $expression, string $pattern): string
    {
        return $expression.' LIKE '.$this->add($pattern).' ESCAPE \''.SearchTerm::LIKE_ESCAPE.'\'';
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->values;
    }
}

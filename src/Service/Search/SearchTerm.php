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

use Thelia\Domain\Catalog\Product\Identifier\Gtin;

/**
 * What the administrator typed in the search box, and the patterns it is looked up with.
 *
 * The patterns escape the wildcards of LIKE with "!", to be compared with `LIKE ? ESCAPE '!'`:
 * a percent sign or an underscore typed in the box is then looked for as such, instead of
 * matching every row. The backslash is left alone, so the result does not depend on the
 * NO_BACKSLASH_ESCAPES mode of the server.
 */
final readonly class SearchTerm
{
    public const LIKE_ESCAPE = '!';

    /** The term as typed, trimmed: compared with = for an exact match. */
    public string $raw;

    /** Matches a value holding the term anywhere. */
    public string $contains;

    /** Matches a value starting with the term. */
    public string $prefix;

    /** The term read as a GTIN once its spaces and dashes are dropped, or null when it is not one. */
    public ?string $gtin;

    public function __construct(string $typed)
    {
        $this->raw = trim($typed);
        $escaped = strtr($this->raw, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $this->contains = '%'.$escaped.'%';
        $this->prefix = $escaped.'%';

        $digits = Gtin::normalize($this->raw);
        $this->gtin = preg_match('/^\d+$/', $digits) === 1 && \in_array(\strlen($digits), Gtin::LENGTHS, true) ? $digits : null;
    }
}

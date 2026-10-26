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

namespace BackOfficeDefaultTwigBundle\Service\Configuration;

use Thelia\Domain\Localization\VisitorCountry\VisitorCountryMode;

/**
 * The visitor country settings of the store configuration screen.
 *
 * The theme installs on a core that may predate the detection of the visitor country:
 * the screen then shows neither the card nor the four settings. Nothing of the core
 * is read before this one question is answered.
 */
final readonly class VisitorCountryContext
{
    /** The headers the usual CDNs and proxies set to the country of the client. */
    public const HEADER_SUGGESTIONS = [
        'CF-IPCountry',
        'CloudFront-Viewer-Country',
        'X-Vercel-IP-Country',
        'X-Country-Code',
    ];

    public const HEADER_DATALIST_ID = 'visitor-country-header-options';

    /**
     * Whether the core carries the visitor country detection, read on an enum shipped
     * with it.
     */
    public static function coreHasVisitorCountry(): bool
    {
        return enum_exists(VisitorCountryMode::class);
    }
}

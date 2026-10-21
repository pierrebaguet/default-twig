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

namespace BackOfficeDefaultTwigBundle\Service\Publication;

/**
 * Whether the core carries the publication of contents and folders (draft, scheduled,
 * published, unpublished), read on a class shipped with it.
 *
 * The theme installs on a core that may predate it: the back office then keeps the
 * online checkbox, the plain preview link and the lists as they were, and the
 * publication routes answer 404. Every screen, route and query of the feature asks
 * this one question.
 */
final readonly class ContentPublicationSupport
{
    /**
     * A string, not `PublicationStatus::class`: the theme must read the same on a core
     * where the class does not exist.
     */
    private const string MARKER_CLASS = 'Thelia\\Domain\\CMS\\Publication\\PublicationStatus';

    public function __construct(
        private string $markerClass = self::MARKER_CLASS,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists($this->markerClass);
    }
}

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

namespace BackOfficeDefaultTwigBundle\Service\Subscription;

/**
 * Whether the core carries the subscriptions, read on a class shipped with them.
 *
 * The theme installs on a core that may predate them: the menu entry, the list and the
 * detail, the plans tab of the product sheet, the section of the customer sheet and the
 * recurring revenue of the dashboard are then left out, and the routes answer 404. Every
 * screen, route and query of the feature asks this one question.
 */
final readonly class SubscriptionSupport
{
    /**
     * The right of the feature, a string and not AdminResources::SUBSCRIPTION: the constant
     * does not exist on a core without the subscriptions.
     */
    public const RESOURCE = 'admin.subscription';

    /**
     * A string, not SubscriptionStateMachine::class: the theme must read the same on a core
     * where the class does not exist.
     */
    private const MARKER_CLASS = 'Thelia\\Domain\\Subscription\\SubscriptionStateMachine';

    public function __construct(
        private string $markerClass = self::MARKER_CLASS,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists($this->markerClass);
    }
}

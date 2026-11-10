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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionSupport;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Loaded when a template first asks, not when the template engine starts.
 */
final readonly class SubscriptionTwigRuntime implements RuntimeExtensionInterface
{
    public function __construct(private SubscriptionSupport $support)
    {
    }

    public function isAvailable(): bool
    {
        return $this->support->isAvailable();
    }
}

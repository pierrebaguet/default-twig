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

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `subscriptions_available()`: whether the core carries the subscriptions, to offer their
 * menu entry, tab, section and figure only then.
 */
final class SubscriptionExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('subscriptions_available', [SubscriptionTwigRuntime::class, 'isAvailable']),
        ];
    }
}

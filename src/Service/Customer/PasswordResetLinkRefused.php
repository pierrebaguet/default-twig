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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

/**
 * The reset link was not sent, for a reason the administrator can read. The message is a
 * translation key of the back-office catalogue.
 */
final class PasswordResetLinkRefused extends \RuntimeException
{
}

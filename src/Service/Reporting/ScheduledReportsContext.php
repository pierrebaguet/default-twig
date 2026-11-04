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

namespace BackOfficeDefaultTwigBundle\Service\Reporting;

use Thelia\Model\ReportMailing;

/**
 * The theme installs on a core that may predate the scheduled report mailings: the screen,
 * its menu entry and its routes exist only on a core that carries them, read on the model
 * shipped with them.
 */
final class ScheduledReportsContext
{
    public static function coreHasScheduledReports(): bool
    {
        return class_exists(ReportMailing::class);
    }
}

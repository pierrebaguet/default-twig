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
 * The publication states a folder list can be narrowed to: the four states of the core,
 * plus the rows whose date has come and that nothing acted on (the scheduled publication
 * task does not run). Kept in the theme so the list reads the same on a core that has
 * no publication: the filter is then never offered.
 */
enum PublicationStateFilter: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Publication scheduled',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Overdue => 'Date passed, not applied',
        };
    }
}

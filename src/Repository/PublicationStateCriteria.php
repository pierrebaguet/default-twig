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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\Publication\PublicationStateFilter;
use Propel\Runtime\ActiveQuery\ModelCriteria;

/**
 * The publication state of a content or a folder written as SQL on its three indexed
 * columns, `visible`, `publish_at` and `unpublish_at`, the way the core reads it
 * (`Thelia\Domain\CMS\Publication\PublicationStatus::of()`):
 *
 * - published: visible;
 * - unpublished: hidden, withdrawal date reached;
 * - scheduled: hidden, a publication date, withdrawal date not reached;
 * - draft: hidden, no publication date, withdrawal date not reached.
 *
 * The column names are written out: on a core without the publication the columns do
 * not exist, and nothing calls this.
 */
final readonly class PublicationStateCriteria
{
    public static function apply(ModelCriteria $query, string $table, PublicationStateFilter $state, \DateTimeInterface $now): void
    {
        $moment = $now->format('Y-m-d H:i:s');
        $visible = $table.'.visible';
        $publishAt = $table.'.publish_at';
        $unpublishAt = $table.'.unpublish_at';
        $hidden = \sprintf('(%s IS NULL OR %s <> 1)', $visible, $visible);

        // One placeholder per clause: Propel binds it with the type of the column it reads.
        match ($state) {
            PublicationStateFilter::Published => $query->where(\sprintf('%s = 1', $visible)),
            PublicationStateFilter::Unpublished => $query
                ->where($hidden)
                ->where(\sprintf('%s IS NOT NULL AND %s <= ?', $unpublishAt, $unpublishAt), $moment),
            PublicationStateFilter::Scheduled => $query
                ->where($hidden)
                ->where(\sprintf('%s IS NOT NULL', $publishAt))
                ->where(\sprintf('(%s IS NULL OR %s > ?)', $unpublishAt, $unpublishAt), $moment),
            PublicationStateFilter::Draft => $query
                ->where($hidden)
                ->where(\sprintf('%s IS NULL', $publishAt))
                ->where(\sprintf('(%s IS NULL OR %s > ?)', $unpublishAt, $unpublishAt), $moment),
            PublicationStateFilter::Overdue => self::applyOverdue($query, $table, $now),
        };
    }

    /**
     * A date has come and nothing acted on it since `$before`: a scheduled row whose
     * publication date is past, a published row whose withdrawal date is past. With
     * `$before` left null, any date past now counts.
     */
    public static function applyOverdue(ModelCriteria $query, string $table, \DateTimeInterface $now, ?\DateTimeInterface $before = null): void
    {
        $moment = $now->format('Y-m-d H:i:s');
        $limit = ($before ?? $now)->format('Y-m-d H:i:s');
        $visible = $table.'.visible';
        $publishAt = $table.'.publish_at';
        $unpublishAt = $table.'.unpublish_at';

        $query->where(\sprintf(
            '((%1$s IS NULL OR %1$s <> 1) AND %2$s IS NOT NULL AND %2$s <= %4$s AND (%3$s IS NULL OR %3$s > %5$s))'
            .' OR (%1$s = 1 AND %3$s IS NOT NULL AND %3$s <= %4$s)',
            $visible,
            $publishAt,
            $unpublishAt,
            self::quote($limit),
            self::quote($moment),
        ));
    }

    /**
     * The value is a date this class formatted itself (`Y-m-d H:i:s`): quoting it is safe,
     * and it spares a clause that reads two columns the binding type of only one of them.
     */
    private static function quote(string $formattedDate): string
    {
        return "'".$formattedDate."'";
    }
}

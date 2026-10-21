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

use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\Map\ContentTableMap;
use Thelia\Model\Map\FolderTableMap;

/**
 * The contents and folders whose publication or withdrawal date has come and that
 * nothing acted on: the scheduled publication task is what acts on them, a shop that
 * does not run it sees them pile up. Only asked on a core that has the publication.
 */
final readonly class PublicationRepository
{
    private const string PENDING_DATE = 'pending_date';

    public function countOverdueContents(\DateTimeInterface $now, \DateTimeInterface $before): int
    {
        $query = ContentQuery::create();
        PublicationStateCriteria::applyOverdue($query, ContentTableMap::TABLE_NAME, $now, $before);

        return $query->count();
    }

    public function countOverdueFolders(\DateTimeInterface $now, \DateTimeInterface $before): int
    {
        $query = FolderQuery::create();
        PublicationStateCriteria::applyOverdue($query, FolderTableMap::TABLE_NAME, $now, $before);

        return $query->count();
    }

    /**
     * The default folder of the overdue content waiting the longest, the list that shows it.
     */
    public function folderOfTheOldestOverdueContent(\DateTimeInterface $now, \DateTimeInterface $before): ?int
    {
        $query = ContentQuery::create();
        PublicationStateCriteria::applyOverdue($query, ContentTableMap::TABLE_NAME, $now, $before);
        $query->withColumn(self::pendingDate(ContentTableMap::TABLE_NAME), self::PENDING_DATE)->orderBy(self::PENDING_DATE);

        $folderId = $query->findOne()?->getDefaultFolderId();

        return null === $folderId ? null : (int) $folderId;
    }

    /**
     * The parent of the overdue folder waiting the longest, the list that shows it.
     */
    public function parentOfTheOldestOverdueFolder(\DateTimeInterface $now, \DateTimeInterface $before): ?int
    {
        $query = FolderQuery::create();
        PublicationStateCriteria::applyOverdue($query, FolderTableMap::TABLE_NAME, $now, $before);
        $query->withColumn(self::pendingDate(FolderTableMap::TABLE_NAME), self::PENDING_DATE)->orderBy(self::PENDING_DATE);

        $parentId = $query->findOne()?->getParent();

        return null === $parentId ? null : (int) $parentId;
    }

    /**
     * The date an overdue row waits for: its withdrawal when online, its publication otherwise.
     */
    private static function pendingDate(string $table): string
    {
        return \sprintf('CASE WHEN %1$s.visible = 1 THEN %1$s.unpublish_at ELSE %1$s.publish_at END', $table);
    }
}

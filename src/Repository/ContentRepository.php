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
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;
use Thelia\Model\Map\ContentFolderTableMap;
use Thelia\Model\Map\ContentTableMap;

final readonly class ContentRepository
{
    /**
     * Position is stored per-folder in the content_folder pivot, not on the content row.
     * Expose it as a virtual column so the listing reflects the folder-scoped order.
     */
    public const FOLDER_POSITION_COLUMN = 'folder_position';

    /**
     * @param PublicationStateFilter|null $state narrows the page to one publication state,
     *                                           read at `$now`; only asked on a core that has them
     *
     * @return ObjectCollection<int, Content>
     */
    public function findInFolderPage(int $folderId, string $locale, int $offset, int $limit, ?PublicationStateFilter $state = null, ?\DateTimeInterface $now = null): ObjectCollection
    {
        $query = ContentQuery::create()
            ->useContentFolderQuery()
                ->filterByFolderId($folderId)
                ->orderByPosition()
            ->endUse()
            ->withColumn(ContentFolderTableMap::COL_POSITION, self::FOLDER_POSITION_COLUMN)
            ->offset($offset)
            ->limit($limit);

        if (null !== $state) {
            PublicationStateCriteria::apply($query, ContentTableMap::TABLE_NAME, $state, $now ?? new \DateTimeImmutable());
        }

        /** @var ObjectCollection<int, Content> $contents */
        $contents = $query->find();

        foreach ($contents as $content) {
            $content->setLocale($locale);
        }

        return $contents;
    }

    public function countInFolder(int $folderId, ?PublicationStateFilter $state = null, ?\DateTimeInterface $now = null): int
    {
        $query = ContentQuery::create()
            ->useContentFolderQuery()
                ->filterByFolderId($folderId)
            ->endUse();

        if (null !== $state) {
            PublicationStateCriteria::apply($query, ContentTableMap::TABLE_NAME, $state, $now ?? new \DateTimeImmutable());
        }

        return $query->count();
    }
}

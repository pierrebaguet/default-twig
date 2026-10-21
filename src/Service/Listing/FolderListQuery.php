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

namespace BackOfficeDefaultTwigBundle\Service\Listing;

use BackOfficeDefaultTwigBundle\Service\Publication\PublicationStateFilter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\HttpFoundation\Request;

/**
 * The state of the folder list the user is on: the sort of its subfolders, the
 * page of its contents and the publication state both are narrowed to. The
 * actions of the list (online toggles, deletions) carry it in their URL, so
 * their redirect lands back on the same page with the same sort and filter.
 */
final readonly class FolderListQuery
{
    public const SORT_FIELDS = ['id', 'title', 'visible', 'position'];

    public const DEFAULT_SORT = 'position';

    public const PUBLICATION_STATE_PARAMETER = 'publication_state';

    public function __construct(
        public ListSort $sort,
        public int $contentPage,
        public ?PublicationStateFilter $publicationState = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            ListSort::fromRequest($request, self::SORT_FIELDS, self::DEFAULT_SORT),
            max(1, (int) $request->query->get('content_page', 1)),
            PublicationStateFilter::tryFrom((string) $request->query->get(self::PUBLICATION_STATE_PARAMETER, '')),
        );
    }

    /**
     * The list without its publication filter, on a core that has no publication.
     */
    public function withoutPublicationState(): self
    {
        return new self($this->sort, $this->contentPage);
    }

    /**
     * The state without the folder: the actions name their own folder_id. The
     * publication filter is named only when one is chosen.
     *
     * @return array{order: string, direction: string, content_page: int, publication_state?: string}
     */
    public function toQueryParams(): array
    {
        $parameters = [
            'order' => $this->sort->field,
            'direction' => $this->sort->direction,
            'content_page' => $this->contentPage,
        ];

        if (null !== $this->publicationState) {
            $parameters[self::PUBLICATION_STATE_PARAMETER] = $this->publicationState->value;
        }

        return $parameters;
    }

    /**
     * @return array{folder_id: int, order: string, direction: string, content_page: int, publication_state?: string}
     */
    public function toListParams(int $folderId): array
    {
        return ['folder_id' => $folderId] + $this->toQueryParams();
    }
}

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

namespace BackOfficeDefaultTwigBundle\Service\Search;

use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;

final readonly class SearchResultsPresenter
{
    public const MIN_TERM_LENGTH = 2;

    public function __construct(
        private SearchRepository $search,
        private AdminAccessChecker $access,
    ) {
    }

    /**
     * The groups the administrator may view, each searched only then: a group on a resource they
     * cannot view costs no query. With $only, that single group is shown, one page at a time.
     *
     * @return array{term: string, too_short: bool, min_length: int, only: ?string, page: int, pages: int, page_size: int, groups: array<string, SearchGroupResult>, filtered_lists: array<string, string>}
     */
    public function buildResults(string $term, string $locale, string $fallbackLocale, ?SearchGroup $only = null, int $page = 1): array
    {
        $groups = array_values(array_filter(
            null === $only ? SearchGroup::cases() : [$only],
            fn (SearchGroup $group): bool => $this->access->canView($group->resource()),
        ));
        $page = null === $only ? 1 : max(1, $page);

        $results = [
            'term' => $term,
            'too_short' => false,
            'min_length' => self::MIN_TERM_LENGTH,
            'only' => null !== $only && [] !== $groups ? $only->value : null,
            'page' => $page,
            'pages' => 1,
            'page_size' => SearchRepository::DISPLAY_LIMIT,
            'groups' => [],
            'filtered_lists' => [],
        ];

        if ($term === '') {
            return $results;
        }

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            $results['too_short'] = true;

            return $results;
        }

        $searchTerm = new SearchTerm($term);
        $offset = ($page - 1) * SearchRepository::DISPLAY_LIMIT;

        foreach ($groups as $group) {
            $result = $this->search->find($group, $searchTerm, $locale, $fallbackLocale, $offset);
            $results['groups'][$group->value] = $result;

            if (null !== $route = $group->filteredListRoute()) {
                $results['filtered_lists'][$group->value] = $route;
            }

            if (null !== $only) {
                $results['pages'] = max(1, (int) ceil($result->total / SearchRepository::DISPLAY_LIMIT));
            }
        }

        return $results;
    }
}

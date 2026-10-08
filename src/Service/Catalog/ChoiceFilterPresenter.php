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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Propel\Runtime\Collection\ObjectCollection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\Filters\Type\CheckboxType;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\Filters\Type\DeltaType;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\FilterService;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ChoiceFilterOtherQuery;
use Thelia\Model\ChoiceFilterQuery;

final readonly class ChoiceFilterPresenter
{
    /**
     * The type of the price facet, drawn as a slider unless the merchant picks otherwise. Written
     * out rather than read from the core's PriceFilter, which an older core does not ship.
     */
    private const string PRICE_FILTER_TYPE = 'price';

    public function __construct(
        private FilterService $filterService,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    public function forCategory(int $categoryId, string $locale): array
    {
        $category = CategoryQuery::create()->filterById($categoryId)->findOne();
        if ($category === null) {
            return $this->emptyContext();
        }

        $templateId = null;
        $resolvedCategoryId = null;
        $choiceFilters = ChoiceFilterQuery::findChoiceFilterByCategory($category, $templateId, $resolvedCategoryId);

        $messages = [];
        $enabled = false;
        $features = new ObjectCollection();
        $attributes = new ObjectCollection();
        $others = new ObjectCollection();

        if ($templateId === null) {
            $choiceFilters = new ObjectCollection();
            $messages[] = $this->translator->trans('This category uses no filter configuration.');
        } else {
            $features = ChoiceFilterQuery::findFeaturesByTemplateId($templateId, [$locale]);
            $attributes = ChoiceFilterQuery::findAttributesByTemplateId($templateId, [$locale]);
            $others = $this->offeredOthers(ChoiceFilterOtherQuery::findOther([$locale]));

            if ($resolvedCategoryId === null) {
                $messages[] = $this->translator->trans(
                    'This category uses the template configuration %templateId.',
                    ['%templateId' => $templateId],
                );
            } elseif ($resolvedCategoryId === (int) $category->getId()) {
                $enabled = true;
                $messages[] = $this->translator->trans('This category uses its own filter configuration.');
            } else {
                $messages[] = $this->translator->trans(
                    'This category uses the filter configuration of category %categoryId.',
                    ['%categoryId' => $resolvedCategoryId],
                );
            }
        }

        $filters = $this->buildFilters($choiceFilters, $features, $attributes, $others);
        $filters = $this->annotateDisplayType($filters, $choiceFilters);

        return [
            'filters' => $filters,
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => $enabled,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    public function forTemplate(int $templateId, string $locale): array
    {
        $features = ChoiceFilterQuery::findFeaturesByTemplateId($templateId, [$locale]);
        $attributes = ChoiceFilterQuery::findAttributesByTemplateId($templateId, [$locale]);
        $others = $this->offeredOthers(ChoiceFilterOtherQuery::findOther([$locale]));
        $choiceFilters = ChoiceFilterQuery::create()
            ->filterByTemplateId($templateId)
            ->orderByPosition()
            ->find();

        $filters = $this->buildFilters($choiceFilters, $features, $attributes, $others);
        $filters = $this->annotateDisplayType($filters, $choiceFilters);

        return [
            'filters' => $filters,
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => \count($choiceFilters) > 0,
            'messages' => [],
        ];
    }

    /**
     * The filters a merchant can arrange, without the ones the core withholds for want of the
     * data they read: the rating facet on a shop without any review module would sit on the
     * screen and never show on the listing.
     *
     * @param array<mixed>|ObjectCollection $others
     *
     * @return array<mixed>|ObjectCollection
     */
    private function offeredOthers(array|ObjectCollection $others): array|ObjectCollection
    {
        // A core older than 3.3 withholds nothing: the theme requires thelia/core ^3.2, where the
        // method does not exist yet.
        // @phpstan-ignore function.alreadyNarrowedType
        if (!method_exists($this->filterService, 'withheldFilterNames')) {
            return $others;
        }

        $withheld = $this->filterService->withheldFilterNames('products');

        if ($withheld === []) {
            return $others;
        }

        $rows = $others instanceof ObjectCollection ? $others->toArray() : $others;

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => !\is_array($row) || !\in_array($row['Type'] ?? null, $withheld, true),
        ));
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    private function emptyContext(): array
    {
        return [
            'filters' => [],
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => false,
            'messages' => [],
        ];
    }

    /**
     * @param iterable<mixed>                                            $choiceFilters
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $features
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $attributes
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $others
     *
     * @return list<array<string, mixed>>
     */
    private function buildFilters(iterable $choiceFilters, iterable $features, iterable $attributes, iterable $others): array
    {
        $features = $features instanceof ObjectCollection ? $features->toArray() : (array) $features;
        $attributes = $attributes instanceof ObjectCollection ? $attributes->toArray() : (array) $attributes;
        $others = $others instanceof ObjectCollection ? $others->toArray() : (array) $others;

        $features = array_map(static fn ($row) => $row + ['Type' => 'feature', 'Visible' => 1], $features);
        $attributes = array_map(static fn ($row) => $row + ['Type' => 'attribute', 'Visible' => 1], $attributes);

        $merged = [];
        $choiceList = $choiceFilters instanceof ObjectCollection ? iterator_to_array($choiceFilters) : (array) $choiceFilters;

        if ($choiceList !== []) {
            foreach ($choiceList as $choiceFilter) {
                if (($attributeId = $choiceFilter->getAttributeId()) !== null) {
                    foreach ($attributes as $key => $attribute) {
                        if ((int) $attribute['Id'] === (int) $attributeId) {
                            $attribute['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                            $merged[] = $attribute;
                            unset($attributes[$key]);
                        }
                    }
                } elseif (($featureId = $choiceFilter->getFeatureId()) !== null) {
                    foreach ($features as $key => $feature) {
                        if ((int) $feature['Id'] === (int) $featureId) {
                            $feature['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                            $merged[] = $feature;
                            unset($features[$key]);
                        }
                    }
                } else {
                    $other = $choiceFilter->getChoiceFilterOther();
                    $type = $other?->getType();
                    if ($type !== null) {
                        foreach ($others as $key => $row) {
                            if (($row['Type'] ?? null) === $type) {
                                $row['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                                $merged[] = $row;
                                unset($others[$key]);
                            }
                        }
                    }
                }
            }
        }

        $merged = [...$merged, ...$attributes, ...$features, ...$others];
        $position = 1;

        return array_map(static function (array $row) use (&$position): array {
            $row['Position'] ??= $position;
            ++$position;

            return $row;
        }, $merged);
    }

    /**
     * The display type shown against each filter comes from the rows in force for the scope being
     * edited — which, for a category without a configuration of its own, are its template's. Read
     * from those rows rather than re-queried on the category alone: a category page that showed
     * the default instead of the inherited value would overwrite the whole inheritance the moment
     * someone saved it to change one line.
     *
     * @param list<array<string, mixed>> $filters
     * @param iterable<mixed>            $choiceFilters
     *
     * @return list<array<string, mixed>>
     */
    private function annotateDisplayType(array $filters, iterable $choiceFilters): array
    {
        $features = [];
        $attributes = [];
        $others = [];

        foreach ($choiceFilters as $choiceFilter) {
            $displayType = (string) $choiceFilter->getType();

            if ($displayType === '') {
                continue;
            }

            if (($featureId = $choiceFilter->getFeatureId()) !== null) {
                $features[(int) $featureId] = $displayType;

                continue;
            }

            if (($attributeId = $choiceFilter->getAttributeId()) !== null) {
                $attributes[(int) $attributeId] = $displayType;

                continue;
            }

            if (($otherType = $choiceFilter->getChoiceFilterOther()?->getType()) !== null) {
                $others[$otherType] = $displayType;
            }
        }

        foreach ($filters as $index => $filter) {
            $type = (string) ($filter['Type'] ?? '');
            $id = (int) ($filter['Id'] ?? 0);

            $filters[$index]['DisplayType'] = match ($type) {
                'feature' => $features[$id] ?? null,
                'attribute' => $attributes[$id] ?? null,
                default => $others[$type] ?? null,
            } ?? ($type === self::PRICE_FILTER_TYPE ? DeltaType::getName() : CheckboxType::getName());
        }

        return $filters;
    }
}

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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Catalog;

use BackOfficeDefaultTwigBundle\Service\Catalog\ChoiceFilterPresenter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\Filters\RatingFilter;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\FilterService;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Catalog\Product\ProductRatingSourceInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Category;
use Thelia\Model\Template;
use Thelia\Test\IntegrationTestCase;

/**
 * The "Filters" section of a category and of a template: which facets the merchant can arrange,
 * under which name, drawn how.
 */
final class ChoiceFilterPresenterTest extends IntegrationTestCase
{
    private Template $template;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->getPropelConnection();

        $this->template = new Template();
        $this->template->setLocale('en_US');
        $this->template->setName('Filters screen template');
        $this->template->save($connection);

        $this->category = $this->createFixtureFactory()->category();
        $this->category->setDefaultTemplateId($this->template->getId())->save($connection);
    }

    public function testTheCategoryScreenNamesTheFiltersInTheLanguageBeingEdited(): void
    {
        $filters = $this->presenter()->forCategory((int) $this->category->getId(), 'fr_FR')['filters'];

        self::assertSame('Nouveauté', $this->row($filters, 'new')['Title'] ?? null);
    }

    public function testAFilterTheCoreWithholdsIsNotOfferedToArrange(): void
    {
        $this->skipWithoutTheListingFacetsOfTheCore();

        $withoutReviews = $this->presenter(withRatingSource: false);
        $withReviews = $this->presenter(withRatingSource: true);

        self::assertNull($this->row($withoutReviews->forCategory((int) $this->category->getId(), 'en_US')['filters'], 'rating'));
        self::assertNull($this->row($withoutReviews->forTemplate((int) $this->template->getId(), 'en_US')['filters'], 'rating'));
        self::assertNotNull($this->row($withReviews->forCategory((int) $this->category->getId(), 'en_US')['filters'], 'rating'));
        self::assertNotNull($this->row($withReviews->forTemplate((int) $this->template->getId(), 'en_US')['filters'], 'rating'));
    }

    public function testThePriceFilterIsASliderUntilTheMerchantChoosesOtherwise(): void
    {
        $this->skipWithoutTheListingFacetsOfTheCore();

        $filters = $this->presenter()->forTemplate((int) $this->template->getId(), 'en_US')['filters'];

        self::assertSame('delta', $this->row($filters, 'price')['DisplayType'] ?? null);
        self::assertSame('checkbox', $this->row($filters, 'availability')['DisplayType'] ?? null);
    }

    /**
     * The facets arrived with the 3.3 core; against an older one there is nothing to judge.
     */
    private function skipWithoutTheListingFacetsOfTheCore(): void
    {
        if (!class_exists(RatingFilter::class)) {
            self::markTestSkipped('The core does not ship the availability, rating and price facets.');
        }
    }

    /**
     * A presenter over a filter list of its own, so that the result does not depend on whether
     * a review module happens to be active on the machine running the suite.
     */
    private function presenter(bool $withRatingSource = false): ChoiceFilterPresenter
    {
        $container = static::getContainer();
        $translator = $container->get(Translator::class);
        $filters = [];

        if (class_exists(RatingFilter::class)) {
            $source = new class implements ProductRatingSourceInterface {
                public function productIdsRatedAtLeast(float $minimumRating, ?array $amongProductIds = null): array
                {
                    return [];
                }
            };
            $filters[] = new RatingFilter($translator, $withRatingSource ? [$source] : []);
        }

        return new ChoiceFilterPresenter(
            new FilterService(
                filters: $filters,
                filterTypes: [],
                langService: $container->get(LangService::class),
                requestStack: $container->get(RequestStack::class),
                translator: $translator,
            ),
            $container->get(TranslatorInterface::class),
        );
    }

    /**
     * @param list<array<string, mixed>> $filters
     *
     * @return array<string, mixed>|null
     */
    private function row(array $filters, string $type): ?array
    {
        foreach ($filters as $filter) {
            if (($filter['Type'] ?? null) === $type) {
                return $filter;
            }
        }

        return null;
    }
}

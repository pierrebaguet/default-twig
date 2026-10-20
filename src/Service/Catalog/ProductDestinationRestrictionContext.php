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

use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Model\DestinationRestriction;
use Thelia\Model\DestinationRestrictionQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductDestinationRestrictionQuery;
use Thelia\Tools\TokenProvider;

/**
 * What the destination restrictions card of the product sheet shows: the groups the
 * product belongs to and the ones it could still join.
 *
 * The theme installs on a core that may predate the destination restrictions: the
 * back office then shows neither the card, nor the Configuration screen, nor its menu.
 */
final readonly class ProductDestinationRestrictionContext
{
    public function __construct(private TokenProvider $tokens)
    {
    }

    /**
     * Whether the core carries the destination restrictions, read on a class shipped
     * with them. Every screen, route and card of the feature asks this one question.
     */
    public static function coreHasDestinationRestrictions(): bool
    {
        return class_exists(DestinationRestrictionFacade::class);
    }

    /**
     * @return array{
     *     destination_restrictions: bool,
     *     product_restrictions: list<array{id: int, title: string, code: string, mode_allow: bool, active: bool}>,
     *     available_restrictions: list<array{id: int, title: string, code: string}>,
     *     destination_restriction_token: string|null,
     * }
     */
    public function build(Product $product, string $uiLocale): array
    {
        if (!self::coreHasDestinationRestrictions()) {
            return [
                'destination_restrictions' => false,
                'product_restrictions' => [],
                'available_restrictions' => [],
                'destination_restriction_token' => null,
            ];
        }

        $attachedIds = [];
        foreach (ProductDestinationRestrictionQuery::create()->filterByProductId($product->getId())->find() as $link) {
            $attachedIds[(int) $link->getDestinationRestrictionId()] = true;
        }

        $attached = [];
        $available = [];
        foreach (DestinationRestrictionQuery::create()->orderByPosition()->find() as $group) {
            $id = (int) $group->getId();
            $title = (string) $group->setLocale($uiLocale)->getTitle();
            $title = '' !== $title ? $title : (string) $group->getCode();

            if (isset($attachedIds[$id])) {
                $attached[] = [
                    'id' => $id,
                    'title' => $title,
                    'code' => (string) $group->getCode(),
                    'mode_allow' => DestinationRestriction::MODE_ALLOW === $group->getMode(),
                    'active' => (bool) $group->getActive(),
                ];
            } else {
                $available[] = ['id' => $id, 'title' => $title, 'code' => (string) $group->getCode()];
            }
        }

        return [
            'destination_restrictions' => true,
            'product_restrictions' => $attached,
            'available_restrictions' => $available,
            'destination_restriction_token' => $this->tokens->assignToken(),
        ];
    }
}

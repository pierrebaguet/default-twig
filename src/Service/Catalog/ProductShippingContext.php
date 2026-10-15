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

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductExcludedDeliveryModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Tools\TokenProvider;

/**
 * What the carriers card of the product sheet shows: the delivery modules the
 * product is kept from, the active ones it could still be kept from, and
 * whether no active carrier is left for it.
 */
final readonly class ProductShippingContext
{
    public function __construct(
        private ModuleRepository $modules,
        private TokenProvider $tokens,
    ) {
    }

    /**
     * @return array{
     *     excluded_carriers: list<array{id: int, title: string, code: string}>,
     *     available_carriers: list<array{id: int, title: string, code: string}>,
     *     every_carrier_excluded: bool,
     *     shipping_token: string|null,
     *     default_currency_symbol: string,
     * }
     */
    public function build(Product $product, string $uiLocale): array
    {
        $excludedIds = [];
        foreach (ProductExcludedDeliveryModuleQuery::create()->filterByProductId($product->getId())->find() as $exclusion) {
            $excludedIds[(int) $exclusion->getModuleId()] = true;
        }

        $excluded = [];
        $available = [];
        foreach ($this->modules->findActiveByType(BaseModule::DELIVERY_MODULE_TYPE, $uiLocale) as $carrier) {
            if (isset($excludedIds[$carrier['id']])) {
                $excluded[] = $carrier;
            } else {
                $available[] = $carrier;
            }
        }

        return [
            'excluded_carriers' => $excluded,
            'available_carriers' => $available,
            'every_carrier_excluded' => [] !== $excluded && [] === $available,
            'shipping_token' => $this->tokens->assignToken(),
            'default_currency_symbol' => (string) CurrencyQuery::create()->findOneByByDefault(1)?->getSymbol(),
        ];
    }
}

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

use Thelia\Core\Security\Resource\AdminResources;

/**
 * The groups of the back-office search, in the order they are shown.
 */
enum SearchGroup: string
{
    case Products = 'products';
    case Combinations = 'combinations';
    case Categories = 'categories';
    case Folders = 'folders';
    case Contents = 'contents';
    case Brands = 'brands';
    case Customers = 'customers';
    case Orders = 'orders';
    case Coupons = 'coupons';
    case Sales = 'sales';
    case Administrators = 'administrators';
    case Modules = 'modules';

    /** The resource an administrator must be allowed to view for the group to be searched at all. */
    public function resource(): string
    {
        return match ($this) {
            self::Products, self::Combinations => AdminResources::PRODUCT,
            self::Categories => AdminResources::CATEGORY,
            self::Folders => AdminResources::FOLDER,
            self::Contents => AdminResources::CONTENT,
            self::Brands => AdminResources::BRAND,
            self::Customers => AdminResources::CUSTOMER,
            self::Orders => AdminResources::ORDER,
            self::Coupons => AdminResources::COUPON,
            self::Sales => AdminResources::SALES,
            self::Administrators => AdminResources::ADMINISTRATOR,
            self::Modules => AdminResources::MODULE,
        };
    }

    /**
     * The list screen that filters on the same term with its `q` parameter, for the groups that
     * have one. The other groups are shown in full by the search itself.
     */
    public function filteredListRoute(): ?string
    {
        return match ($this) {
            self::Products => 'admin.products.default',
            self::Customers => 'admin.customers',
            self::Orders => 'admin.order.list',
            default => null,
        };
    }
}

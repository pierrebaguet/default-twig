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

namespace BackOfficeDefaultTwigBundle\DTO\Report;

enum SearchLogAvailability: string
{
    case ModuleMissing = 'module_missing';
    case ModuleInactive = 'module_inactive';
    case TableMissing = 'table_missing';
    case WithoutCounter = 'without_counter';
    case WithCounter = 'with_counter';

    public function isReadable(): bool
    {
        return match ($this) {
            self::WithoutCounter, self::WithCounter => true,
            self::ModuleMissing, self::ModuleInactive, self::TableMissing => false,
        };
    }
}

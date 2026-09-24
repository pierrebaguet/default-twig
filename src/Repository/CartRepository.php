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

use Propel\Runtime\Propel;

final class CartRepository
{
    /**
     * The creation date of the oldest cart still in base. Read on `created_at`, not on
     * the primary key: imported or backdated carts break the id order. The scan is the
     * one the funnel queries already pay on every render.
     */
    public function oldestCartCreatedAt(): ?\DateTimeImmutable
    {
        $statement = Propel::getConnection()->prepare('SELECT MIN(created_at) AS created_at FROM cart');
        $statement->execute();
        $createdAt = $statement->fetchColumn();

        if (!\is_string($createdAt) || '' === $createdAt) {
            return null;
        }

        return new \DateTimeImmutable($createdAt);
    }
}

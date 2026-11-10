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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Subscription;

use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SubscriptionFiltersTest extends TestCase
{
    public function testNothingAskedIsAnEmptyFilterWithTheDefaultSort(): void
    {
        $filters = SubscriptionFilters::fromRequest(new Request());

        self::assertTrue($filters->isEmpty());
        self::assertSame([], $filters->toQueryParams(), 'The defaults are left out of the URL.');
    }

    public function testOnlyTheKnownStatesAreKept(): void
    {
        $filters = SubscriptionFilters::fromRequest(new Request(['status' => ['active', 'nonsense', 'active', 'paused', ['x']]]));

        self::assertSame(['active', 'paused'], $filters->statuses);
    }

    public function testTheDatesAreStrictAndAnInvertedRangeIsPutRight(): void
    {
        $filters = SubscriptionFilters::fromRequest(new Request(['next_from' => '2031-05-10', 'next_to' => '2031-05-01']));

        self::assertSame('2031-05-01 00:00:00', $filters->nextBillingFrom?->format('Y-m-d H:i:s'));
        self::assertSame('2031-05-10 23:59:59', $filters->nextBillingTo?->format('Y-m-d H:i:s'));

        $broken = SubscriptionFilters::fromRequest(new Request(['next_from' => '2031-02-30', 'next_to' => 'tomorrow']));
        self::assertNull($broken->nextBillingFrom);
        self::assertNull($broken->nextBillingTo);
    }

    public function testAPlanThatIsNotAPositiveNumberIsIgnored(): void
    {
        self::assertNull(SubscriptionFilters::fromRequest(new Request(['plan' => 'abc']))->planId);
        self::assertNull(SubscriptionFilters::fromRequest(new Request(['plan' => '-2']))->planId);
    }

    public function testASortOutsideTheAllowedOnesFallsBack(): void
    {
        $filters = SubscriptionFilters::fromRequest(new Request(['order' => 'password; drop', 'direction' => 'sideways']));

        self::assertSame(SubscriptionFilters::DEFAULT_SORT, $filters->sort);
        self::assertSame(SubscriptionFilters::DEFAULT_DIRECTION, $filters->direction);
        self::assertSame('next_billing_at', SubscriptionFilters::fromRequest(new Request(['order' => 'next_billing_at']))->sort);
    }

    public function testTheQueryParametersRoundTrip(): void
    {
        $request = new Request(['status' => ['active'], 'q' => ' Ada ', 'customer_id' => '7', 'plan' => '3', 'next_from' => '2031-01-01', 'order' => 'ref', 'direction' => 'asc']);
        $filters = SubscriptionFilters::fromRequest($request);

        $again = SubscriptionFilters::fromRequest(new Request($filters->toQueryParams()));

        self::assertEquals($filters, $again);
        self::assertSame('Ada', $filters->search);
        self::assertSame(7, $filters->customerId);
        self::assertSame(3, $filters->planId);
    }

    public function testEachFilterIsRemovedOnItsOwn(): void
    {
        $filters = SubscriptionFilters::fromRequest(new Request(['status' => ['active'], 'q' => 'Ada', 'customer_id' => '7', 'plan' => '4', 'next_from' => '2031-01-01', 'next_to' => '2031-02-01']));

        self::assertSame([], $filters->withoutFilter(SubscriptionFilters::KEY_STATUSES)->statuses);
        self::assertSame('', $filters->withoutFilter(SubscriptionFilters::KEY_SEARCH)->search);
        self::assertNull($filters->withoutFilter(SubscriptionFilters::KEY_CUSTOMER_ID)->customerId);
        self::assertNull($filters->withoutFilter(SubscriptionFilters::KEY_PLAN)->planId);
        self::assertSame(7, $filters->withoutFilter(SubscriptionFilters::KEY_PLAN)->customerId, 'The others stay.');
        $noDates = $filters->withoutFilter(SubscriptionFilters::KEY_NEXT_BILLING);
        self::assertNull($noDates->nextBillingFrom);
        self::assertNull($noDates->nextBillingTo);
        self::assertSame(['active'], $noDates->statuses, 'The others stay.');
        self::assertSame($filters, $filters->withoutFilter('unknown'));
    }
}

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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\SubscriptionRepository;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionSupport;
use BackOfficeDefaultTwigBundle\Tests\Support\BuildsSubscriptions;
use Propel\Runtime\Propel;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The monthly recurring revenue of the dashboard: one aggregate that spreads the price of an
 * installment over the months it pays for, in the default currency.
 */
final class SubscriptionRecurringRevenueTest extends IntegrationTestCase
{
    use BuildsSubscriptions;

    private FixtureFactory $factory;

    private SubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!(new SubscriptionSupport())->isAvailable()) {
            self::markTestSkipped('The core does not carry the subscriptions.');
        }

        $this->factory = $this->createFixtureFactory();
        $this->subscriptions = new SubscriptionRepository();
    }

    /**
     * @return iterable<string, array{string, int, float}>
     */
    public static function periods(): iterable
    {
        yield 'every month' => ['month', 1, 1.0];
        yield 'every 2 months' => ['month', 2, 0.5];
        yield 'every quarter' => ['quarter', 1, 1 / 3];
        yield 'every 2 quarters' => ['quarter', 2, 1 / 6];
        yield 'every year' => ['year', 1, 1 / 12];
        yield 'every week' => ['week', 1, 52 / 12];
        yield 'every 4 weeks' => ['week', 4, 13 / 12];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('periods')]
    public function testAPeriodIsNormalisedToAMonth(string $unit, int $count, float $share): void
    {
        $row = Propel::getConnection()->query(
            'SELECT '.SubscriptionRepository::monthlyFactorSql("'".$unit."'", (string) $count),
        )->fetchColumn();

        self::assertEqualsWithDelta($share, (float) $row, 0.0001);
    }

    public function testTheFigureAddsUpWhatTheActiveSubscriptionsBringInAMonth(): void
    {
        $before = $this->subscriptions->monthlyRecurringRevenue();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $usd = CurrencyQuery::create()->findOneByCode('USD');

        // 20 + 10 + 52 + 10 + 10 a month, then 10 from a subscription taken in another currency.
        $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '10.000000', 'Quantity' => 2]);
        $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '30.000000', 'PeriodUnit' => 'quarter', 'Status' => 'payment_failed']);
        $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '12.000000', 'PeriodUnit' => 'week']);
        $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '120.000000', 'PeriodUnit' => 'year']);
        $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '20.000000', 'PeriodCount' => 2]);
        $expected = 102.0;

        if (null !== $usd && !$usd->getByDefault() && (float) $usd->getRate() > 0) {
            $this->aSubscriptionRow($customer, $product, $plan, ['CurrencyId' => $usd->getId(), 'UnitPrice' => number_format(10 * (float) $usd->getRate(), 6, '.', '')]);
            $expected += 10.0;
        }

        self::assertEqualsWithDelta($before + $expected, $this->subscriptions->monthlyRecurringRevenue(), 0.011);
    }

    public function testTheStatesThatBringNothingAreLeftOut(): void
    {
        $before = $this->subscriptions->monthlyRecurringRevenue();
        [$product, $plan] = $this->aProductWithAPlan();
        $customer = $this->factory->customer($this->factory->customerTitle());

        foreach (['trial', 'suspended', 'paused', 'cancelled', 'ended'] as $status) {
            $this->aSubscriptionRow($customer, $product, $plan, ['UnitPrice' => '1000.000000', 'Status' => $status]);
        }

        self::assertEqualsWithDelta($before, $this->subscriptions->monthlyRecurringRevenue(), 0.001);
    }
}

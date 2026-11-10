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

namespace BackOfficeDefaultTwigBundle\Tests\Support;

use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\LangQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\Subscription;
use Thelia\Model\SubscriptionHistory;
use Thelia\Model\SubscriptionOrder;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanPrice;
use Thelia\Test\FixtureFactory;

/**
 * Rows of the subscription tables written straight to the database, for the screens that
 * read them: the state of the subscription is the one the test gives, no state machine runs.
 *
 * For a test that has a `$this->factory` (FixtureFactory) and a Propel connection.
 */
trait BuildsSubscriptions
{
    private function aProductWithAPlan(string $title = 'Monthly box', ?Currency $currency = null): array
    {
        /** @var FixtureFactory $factory */
        $factory = $this->factory;
        $currency ??= Currency::getDefaultCurrency();
        $product = $factory->product($factory->category(), $factory->taxRule(), $currency, ['basePrice' => 10.0]);

        $plan = (new SubscriptionPlan())
            ->setProductId($product->getId())
            ->setPeriodUnit('month')
            ->setPeriodCount(1)
            ->setTrialDays(0)
            ->setPausable(1)
            ->setVisible(1)
            ->setPosition(1);
        $plan->setLocale('en_US')->setTitle($title);
        $plan->save($this->getPropelConnection());

        (new SubscriptionPlanPrice())
            ->setSubscriptionPlanId($plan->getId())
            ->setCurrencyId($currency->getId())
            ->setPrice('9.900000')
            ->setFromDefaultCurrency(0)
            ->save($this->getPropelConnection());

        return [$product, $plan];
    }

    /**
     * @param array<string, mixed> $values by phpName, over a monthly subscription
     */
    private function aSubscriptionRow(Customer $customer, Product $product, SubscriptionPlan $plan, array $values = []): Subscription
    {
        $module = ModuleQuery::create()->findOne() ?? throw new \LogicException('The test shop has no module.');
        $order = $this->factory->order($customer);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();

        $subscription = (new Subscription())
            ->setRef('SUB'.strtoupper(bin2hex(random_bytes(6))))
            ->setCustomerId($customer->getId())
            ->setSubscriptionPlanId($plan->getId())
            ->setProductSaleElementsId($pse?->getId())
            ->setQuantity(1)
            ->setCurrencyId(Currency::getDefaultCurrency()->getId())
            ->setLangId(LangQuery::create()->findOneByLocale('en_US')?->getId() ?? 1)
            ->setPeriodUnit('month')
            ->setPeriodCount(1)
            ->setPausable(1)
            ->setUnitPrice('9.900000')
            ->setInitialOrderId($order->getId())
            ->setDeliveryModuleId($module->getId())
            ->setPaymentModuleId($module->getId())
            ->setStatus('active')
            ->setBillingAnchorDay(1)
            ->setCurrentPeriodStart(new \DateTime('2030-01-01 10:00:00'))
            ->setCurrentPeriodEnd(new \DateTime('2030-02-01 10:00:00'))
            ->setNextBillingAt(new \DateTime('2030-02-01 10:00:00'))
            ->setInstallmentsBilled(1);

        foreach ($values as $name => $value) {
            $subscription->setByName($name, $value);
        }

        $subscription->save($this->getPropelConnection());

        return $subscription;
    }

    private function anInstallmentRow(Subscription $subscription, int $number, string $state = 'paid', bool $withOrder = true): SubscriptionOrder
    {
        $installment = (new SubscriptionOrder())
            ->setSubscriptionId($subscription->getId())
            ->setInstallmentNumber($number)
            ->setState($state)
            ->setAttemptCount(1);

        if ($withOrder) {
            $installment->setOrderId($this->factory->order($subscription->getCustomer())->getId());
        }

        $installment->save($this->getPropelConnection());

        return $installment;
    }

    private function aHistoryRow(Subscription $subscription, ?string $from, string $to, string $actor = 'system', ?int $actorId = null, ?string $note = null): void
    {
        (new SubscriptionHistory())
            ->setSubscriptionId($subscription->getId())
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setActorType($actor)
            ->setActorId($actorId)
            ->setNote($note)
            ->save($this->getPropelConnection());
    }

    private function aModule(): Module
    {
        return ModuleQuery::create()->findOne() ?? throw new \LogicException('The test shop has no module.');
    }
}

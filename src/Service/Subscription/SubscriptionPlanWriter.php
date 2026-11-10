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

namespace BackOfficeDefaultTwigBundle\Service\Subscription;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Config\DatabaseConfiguration;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanPriceQuery;
use Thelia\Model\SubscriptionPlanQuery;

/**
 * Writes the plans of a product from the plan form of the product sheet, with the rules of
 * the API processor of the core (SubscriptionPlanProcessor): the plan and its prices are
 * saved in one transaction, the prices sent replace the ones the plan had, one per
 * currency, and a plan without a price in the default currency is refused. The API
 * processor is not called: it works on the API resource, not on what a form sends.
 *
 * A running subscription is not touched: it holds its own copy of the plan, and deleting a
 * plan only empties the link the subscription keeps to it.
 */
final readonly class SubscriptionPlanWriter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Creates the plan of the product when no plan is given, updates it otherwise.
     *
     * @throws InvalidSubscriptionPlanException
     */
    public function save(int $productId, ?SubscriptionPlan $plan, SubscriptionPlanInput $input): SubscriptionPlan
    {
        $defaultCurrencyId = (int) Currency::getDefaultCurrency()->getId();

        if (!isset($input->prices[$defaultCurrencyId])) {
            throw new InvalidSubscriptionPlanException($this->translator->trans('A plan needs a price in the default currency.'));
        }

        foreach (array_keys($input->prices) as $currencyId) {
            if (null === CurrencyQuery::create()->findPk($currencyId)) {
                throw new InvalidSubscriptionPlanException($this->translator->trans('A price is given in a currency the shop does not have.'));
            }
        }

        $connection = Propel::getWriteConnection(DatabaseConfiguration::THELIA_CONNECTION_NAME);
        $connection->beginTransaction();

        try {
            $plan ??= (new SubscriptionPlan())
                ->setProductId($productId)
                ->setPosition($this->nextPosition($productId));

            $plan
                ->setPeriodUnit($input->periodUnit)
                ->setPeriodCount($input->periodCount)
                ->setTrialDays($input->trialDays)
                ->setInstallmentCount($input->installmentCount)
                ->setCommitmentCount($input->commitmentCount)
                ->setPausable((int) $input->pausable)
                ->setVisible((int) $input->visible);

            foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
                $locale = (string) $lang->getLocale();
                $plan->setLocale($locale)->setTitle($input->titles[$locale] ?? null);
            }

            $plan->save($connection);
            $this->replacePrices((int) $plan->getId(), $input->prices);

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $plan;
    }

    public function delete(SubscriptionPlan $plan): void
    {
        $plan->delete();
    }

    public function toggleVisibility(SubscriptionPlan $plan): void
    {
        $plan->setVisible(1 === (int) $plan->getVisible() ? 0 : 1)->save();
    }

    /**
     * Moves the plan one step among those of its product. The positions are renumbered from
     * one, so that plans that share a position (as the API lets them) end up ordered.
     */
    public function move(SubscriptionPlan $plan, bool $up): void
    {
        $ids = [];

        foreach (SubscriptionPlanQuery::create()->filterByProductId($plan->getProductId())->orderByPosition()->orderById()->find() as $sibling) {
            $ids[] = (int) $sibling->getId();
        }

        $index = array_search((int) $plan->getId(), $ids, true);
        $target = false === $index ? null : $index + ($up ? -1 : 1);

        if (null === $target || !isset($ids[$target])) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        foreach ($ids as $position => $id) {
            SubscriptionPlanQuery::create()->filterById($id)->update(['Position' => $position + 1]);
        }
    }

    /**
     * @param array<int, string> $prices currency id => price
     */
    private function replacePrices(int $planId, array $prices): void
    {
        foreach ($prices as $currencyId => $price) {
            SubscriptionPlanPriceQuery::create()
                ->filterBySubscriptionPlanId($planId)
                ->filterByCurrencyId($currencyId)
                ->findOneOrCreate()
                ->setPrice(number_format(round((float) $price, 6), 6, '.', ''))
                ->setFromDefaultCurrency(0)
                ->save();
        }

        SubscriptionPlanPriceQuery::create()
            ->filterBySubscriptionPlanId($planId)
            ->filterByCurrencyId(array_keys($prices), Criteria::NOT_IN)
            ->delete();
    }

    private function nextPosition(int $productId): int
    {
        $last = SubscriptionPlanQuery::create()->filterByProductId($productId)->orderByPosition(Criteria::DESC)->findOne();

        return null === $last ? 1 : (int) $last->getPosition() + 1;
    }
}

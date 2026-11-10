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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanQuery;
use Thelia\Tools\TokenProvider;

/**
 * What the plans tab of the product sheet shows: the plans of the product with their
 * prices, and the form of the plan being created or edited, with a title field per active
 * language and a price field per currency.
 *
 * The plans belong to the catalogue, so the tab follows the right on the product (view to
 * read it, update to change it), not the right on the subscriptions.
 *
 * Without the core the answer is `available: false` and nothing is read.
 */
final readonly class SubscriptionPlansContext
{
    public function __construct(
        private SubscriptionSupport $support,
        private AdminAccessChecker $access,
        private SubscriptionLabels $labels,
        private MoneyFormatter $money,
        private TokenProvider $tokens,
    ) {
    }

    /**
     * @return array{subscription_plans: array<string, mixed>}
     */
    public function build(Product $product, string $uiLocale, ?int $editPlanId, bool $creating): array
    {
        if (!$this->support->isAvailable()) {
            return ['subscription_plans' => ['available' => false]];
        }

        $plans = SubscriptionPlanQuery::create()
            ->filterByProductId($product->getId())
            ->orderByPosition()
            ->orderById()
            ->find();

        $currencies = [];
        foreach (CurrencyQuery::create()->orderByPosition()->find() as $currency) {
            $currencies[] = ['id' => (int) $currency->getId(), 'code' => (string) $currency->getCode(), 'is_default' => (bool) $currency->getByDefault()];
        }

        $defaultCurrencyId = (int) Currency::getDefaultCurrency()->getId();
        $rows = [];
        $editing = null;

        foreach ($plans as $plan) {
            $rows[] = $this->row($plan, $uiLocale, $currencies, $defaultCurrencyId);

            if ($plan->getId() === $editPlanId) {
                $editing = $plan;
            }
        }

        $languages = [];
        foreach (LangQuery::create()->filterByActive(1)->orderByPosition()->find() as $lang) {
            $languages[] = ['locale' => (string) $lang->getLocale(), 'code' => strtoupper((string) $lang->getCode()), 'title' => (string) $lang->getTitle()];
        }

        return ['subscription_plans' => [
            'available' => true,
            'can_update' => $this->access->canUpdate('admin.product'),
            'plans' => $rows,
            'currencies' => $currencies,
            'default_currency_id' => $defaultCurrencyId,
            'languages' => $languages,
            'form' => $creating || null !== $editing ? $this->form($editing, $languages) : null,
            'token' => $this->tokens->assignToken(),
        ]];
    }

    /**
     * @param list<array{id: int, code: string, is_default: bool}> $currencies
     *
     * @return array<string, mixed>
     */
    private function row(SubscriptionPlan $plan, string $uiLocale, array $currencies, int $defaultCurrencyId): array
    {
        $plan->setLocale($uiLocale);
        $title = (string) $plan->getTitle();

        $prices = [];
        foreach ($plan->getSubscriptionPlanPrices(new Criteria()) as $price) {
            $prices[(int) $price->getCurrencyId()] = (string) $price->getPrice();
        }

        $formatted = [];
        foreach ($currencies as $currency) {
            if (isset($prices[$currency['id']])) {
                $formatted[] = $this->money->format((float) $prices[$currency['id']], $uiLocale, $currency['code']);
            }
        }

        return [
            'id' => (int) $plan->getId(),
            'title' => '' !== $title ? $title : '#'.$plan->getId(),
            'period' => $this->labels->period((string) $plan->getPeriodUnit(), (int) $plan->getPeriodCount()),
            'trial_days' => (int) $plan->getTrialDays(),
            'installment_count' => $plan->getInstallmentCount(),
            'commitment_count' => $plan->getCommitmentCount(),
            'pausable' => 1 === (int) $plan->getPausable(),
            'visible' => 1 === (int) $plan->getVisible(),
            'prices' => $formatted,
            'has_default_price' => isset($prices[$defaultCurrencyId]),
        ];
    }

    /**
     * @param list<array{locale: string, code: string, title: string}> $languages
     *
     * @return array<string, mixed>
     */
    private function form(?SubscriptionPlan $plan, array $languages): array
    {
        $titles = [];
        $prices = [];

        if (null !== $plan) {
            foreach ($languages as $language) {
                $titles[$language['locale']] = (string) $plan->setLocale($language['locale'])->getTitle();
            }

            foreach ($plan->getSubscriptionPlanPrices(new Criteria()) as $price) {
                $prices[(int) $price->getCurrencyId()] = rtrim(rtrim((string) $price->getPrice(), '0'), '.');
            }
        }

        return [
            'plan_id' => $plan?->getId(),
            'period_unit' => $plan?->getPeriodUnit() ?? 'month',
            'period_count' => $plan?->getPeriodCount() ?? 1,
            'trial_days' => $plan?->getTrialDays() ?? 0,
            'installment_count' => $plan?->getInstallmentCount(),
            'commitment_count' => $plan?->getCommitmentCount(),
            'pausable' => null !== $plan && 1 === (int) $plan->getPausable(),
            'visible' => null === $plan || 1 === (int) $plan->getVisible(),
            'titles' => $titles,
            'prices' => $prices,
        ];
    }
}

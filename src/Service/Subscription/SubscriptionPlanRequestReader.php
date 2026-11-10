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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the plan form of the product sheet with the rules of the plan of the API: a known
 * period unit, a period count above zero, a trial of zero days or more, an installment or
 * commitment count that is empty or above zero, a price that is a number of zero or more. The
 * price in the default currency is checked by the writer, which knows the currencies; a title
 * in at least one language is asked here, so that a plan is never listed blank.
 */
final readonly class SubscriptionPlanRequestReader
{
    private const UNITS = ['week', 'month', 'quarter', 'year'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws InvalidSubscriptionPlanException
     */
    public function read(Request $request): SubscriptionPlanInput
    {
        $unit = (string) $request->request->get('period_unit', '');

        if (!\in_array($unit, self::UNITS, true)) {
            throw new InvalidSubscriptionPlanException($this->translator->trans('The period unit is not valid.'));
        }

        $periodCount = $this->integer($request->request->get('period_count'), $this->translator->trans('The period count must be a number above zero.'), 1);
        $trialDays = $this->integer($request->request->get('trial_days', '0'), $this->translator->trans('The trial must be a number of days, zero or more.'), 0) ?? 0;

        $titles = [];
        foreach ($request->request->all('title') as $locale => $title) {
            if (\is_string($title) && '' !== trim($title)) {
                $titles[(string) $locale] = trim($title);
            }
        }

        if ([] === $titles) {
            throw new InvalidSubscriptionPlanException($this->translator->trans('Give the plan a title in at least one language.'));
        }

        $prices = [];
        foreach ($request->request->all('price') as $currencyId => $price) {
            $price = \is_string($price) ? trim(str_replace(',', '.', $price)) : '';

            if ('' === $price) {
                continue;
            }

            if (!is_numeric($price) || (float) $price < 0) {
                throw new InvalidSubscriptionPlanException($this->translator->trans('A price is a number, zero or more.'));
            }

            $prices[(int) $currencyId] = $price;
        }

        return new SubscriptionPlanInput(
            periodUnit: $unit,
            periodCount: $periodCount ?? 1,
            trialDays: $trialDays,
            installmentCount: $this->integer($request->request->get('installment_count', ''), $this->translator->trans('The number of installments must be empty or above zero.'), 1, true),
            commitmentCount: $this->integer($request->request->get('commitment_count', ''), $this->translator->trans('The commitment must be empty or above zero.'), 1, true),
            pausable: $request->request->getBoolean('pausable'),
            visible: $request->request->getBoolean('visible'),
            titles: $titles,
            prices: $prices,
        );
    }

    private function integer(mixed $value, string $message, int $minimum, bool $nullable = false): ?int
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';

        if ('' === $value && $nullable) {
            return null;
        }

        if (1 !== preg_match('/^\d+$/', $value) || (int) $value < $minimum) {
            throw new InvalidSubscriptionPlanException($message);
        }

        return (int) $value;
    }
}

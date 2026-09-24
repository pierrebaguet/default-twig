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

namespace BackOfficeDefaultTwigBundle\Service\Dashboard;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The period pills shared by the screens that read a DateRange preset from the
 * `period` query parameter: one pill per preset, linking to the given route.
 */
final readonly class PeriodOptions
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $routeParameters
     *
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    public function build(DateRange $current, string $routeName, array $routeParameters = []): array
    {
        $labels = [
            DateRange::PRESET_TODAY => $this->translator->trans('Today'),
            DateRange::PRESET_SEVEN_DAYS => $this->translator->trans('7 days'),
            DateRange::PRESET_THIRTY_DAYS => $this->translator->trans('30 days'),
            DateRange::PRESET_NINETY_DAYS => $this->translator->trans('90 days'),
            DateRange::PRESET_THIS_MONTH => $this->translator->trans('This month'),
            DateRange::PRESET_THIS_YEAR => $this->translator->trans('This year'),
        ];

        $options = [];
        foreach (DateRange::ALLOWED_PRESETS as $preset) {
            $options[] = [
                'value' => $preset,
                'label' => $labels[$preset],
                'active' => $preset === $current->preset,
                'url' => $this->urls->generate($routeName, ['period' => $preset] + $routeParameters),
            ];
        }

        return $options;
    }
}

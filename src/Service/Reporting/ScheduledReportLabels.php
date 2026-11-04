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

namespace BackOfficeDefaultTwigBundle\Service\Reporting;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Reporting\Mailing\ReportFrequency;
use Thelia\Domain\Reporting\Mailing\ReportPeriod;
use Thelia\Model\ReportMailing;

/**
 * The words the screens of the scheduled reports use for a period, a weekday and a schedule.
 */
final readonly class ScheduledReportLabels
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function period(ReportPeriod $period): string
    {
        return match ($period) {
            ReportPeriod::Yesterday => $this->translator->trans('Yesterday'),
            ReportPeriod::Last7Days => $this->translator->trans('The last 7 days'),
            ReportPeriod::PreviousWeek => $this->translator->trans('The previous week (Monday to Sunday)'),
            ReportPeriod::PreviousMonth => $this->translator->trans('The previous month'),
            ReportPeriod::MonthToDate => $this->translator->trans('The month so far (up to yesterday)'),
        };
    }

    /**
     * @return array<string, int> weekday number (1 Monday to 7 Sunday) by name
     */
    public function weekdays(): array
    {
        return [
            $this->translator->trans('Monday') => 1,
            $this->translator->trans('Tuesday') => 2,
            $this->translator->trans('Wednesday') => 3,
            $this->translator->trans('Thursday') => 4,
            $this->translator->trans('Friday') => 5,
            $this->translator->trans('Saturday') => 6,
            $this->translator->trans('Sunday') => 7,
        ];
    }

    public function schedule(ReportMailing $mailing): string
    {
        $hour = \sprintf('%02d:00', (int) $mailing->getSendHour());
        $parameters = ['%hour%' => $hour];

        return match (ReportFrequency::tryFrom((string) $mailing->getFrequency())) {
            ReportFrequency::Daily => $this->translator->trans('Every day from %hour%', $parameters),
            ReportFrequency::Weekly => match ((int) $mailing->getDayOfWeek()) {
                1 => $this->translator->trans('Every Monday from %hour%', $parameters),
                2 => $this->translator->trans('Every Tuesday from %hour%', $parameters),
                3 => $this->translator->trans('Every Wednesday from %hour%', $parameters),
                4 => $this->translator->trans('Every Thursday from %hour%', $parameters),
                5 => $this->translator->trans('Every Friday from %hour%', $parameters),
                6 => $this->translator->trans('Every Saturday from %hour%', $parameters),
                default => $this->translator->trans('Every Sunday from %hour%', $parameters),
            },
            ReportFrequency::Monthly => $this->translator->trans('On day %day% of every month, from %hour%', $parameters + ['%day%' => (int) $mailing->getDayOfMonth()]),
            null => '',
        };
    }
}

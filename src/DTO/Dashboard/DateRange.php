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

namespace BackOfficeDefaultTwigBundle\DTO\Dashboard;

use Symfony\Component\HttpFoundation\Request;

/**
 * Inclusive datetime range used by every dashboard analytics query. `previous()`
 * returns the same-length window immediately before $from for variation maths.
 */
final readonly class DateRange
{
    public const PRESET_TODAY = 'today';
    public const PRESET_SEVEN_DAYS = '7days';
    public const PRESET_THIRTY_DAYS = '30days';
    public const PRESET_NINETY_DAYS = '90days';
    public const PRESET_THIS_MONTH = 'month';
    public const PRESET_THIS_YEAR = 'year';

    /** Free dates, given as `from` and `to`: never offered as a pill. */
    public const PRESET_CUSTOM = 'custom';

    public const MAX_CUSTOM_DAYS = 1096;

    private const CUSTOM_DATE_FORMAT = '!Y-m-d';

    public const ALLOWED_PRESETS = [
        self::PRESET_TODAY,
        self::PRESET_SEVEN_DAYS,
        self::PRESET_THIRTY_DAYS,
        self::PRESET_NINETY_DAYS,
        self::PRESET_THIS_MONTH,
        self::PRESET_THIS_YEAR,
    ];

    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $preset,
    ) {
    }

    public static function fromPreset(string $preset): self
    {
        $preset = \in_array($preset, self::ALLOWED_PRESETS, true) ? $preset : self::PRESET_THIRTY_DAYS;
        $now = new \DateTimeImmutable('now');
        $endOfDay = $now->setTime(23, 59, 59);

        [$from, $to] = match ($preset) {
            self::PRESET_TODAY => [$now->setTime(0, 0), $endOfDay],
            self::PRESET_SEVEN_DAYS => [$now->modify('-6 days')->setTime(0, 0), $endOfDay],
            self::PRESET_THIRTY_DAYS => [$now->modify('-29 days')->setTime(0, 0), $endOfDay],
            self::PRESET_NINETY_DAYS => [$now->modify('-89 days')->setTime(0, 0), $endOfDay],
            self::PRESET_THIS_MONTH => [$now->modify('first day of this month')->setTime(0, 0), $endOfDay],
            self::PRESET_THIS_YEAR => [$now->modify('first day of January')->setTime(0, 0), $endOfDay],
        };

        return new self($from, $to, $preset);
    }

    /**
     * The range of the request: `period=custom&from=Y-m-d&to=Y-m-d` when the dates are valid,
     * else the preset (30 days by default).
     */
    public static function fromRequest(Request $request, ?\DateTimeImmutable $today = null): self
    {
        $period = (string) $request->query->get('period', self::PRESET_THIRTY_DAYS);

        if (self::PRESET_CUSTOM === $period) {
            $custom = self::custom(
                (string) $request->query->get('from', ''),
                (string) $request->query->get('to', ''),
                $today ?? new \DateTimeImmutable('today'),
            );

            if (null !== $custom) {
                return $custom;
            }
        }

        return self::fromPreset($period);
    }

    /**
     * Two days, both included, from 00:00:00 to 23:59:59. Null unless both are real dates
     * (Y-m-d), the first is not after the second, the second is not after today, and the
     * period spans at most 1096 days.
     */
    public static function custom(string $from, string $to, \DateTimeImmutable $today): ?self
    {
        $start = self::parseDay($from);
        $end = self::parseDay($to);

        if (null === $start || null === $end || $start > $end || $end > $today->setTime(0, 0)) {
            return null;
        }

        if ((int) $start->diff($end)->format('%a') + 1 > self::MAX_CUSTOM_DAYS) {
            return null;
        }

        return new self($start, $end->setTime(23, 59, 59), self::PRESET_CUSTOM);
    }

    private static function parseDay(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::CUSTOM_DATE_FORMAT, $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function previous(): self
    {
        if (self::PRESET_CUSTOM === $this->preset) {
            // The same number of days right before: exact whatever the months crossed.
            return new self(
                $this->from->modify(\sprintf('-%d days', $this->days())),
                $this->from->modify('-1 second'),
                $this->preset,
            );
        }

        $duration = $this->from->diff($this->to->modify('+1 second'));
        $previousTo = $this->from->modify('-1 second');
        $previousFrom = $previousTo->sub($duration)->modify('+1 second');

        return new self($previousFrom, $previousTo, $this->preset);
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diff($this->to)->format('%a') + 1);
    }

    public function cacheKey(string $prefix): string
    {
        return 'bo_dashboard_'.$prefix.'_'.$this->preset
            .'_'.$this->from->format('Ymd')
            .'_'.$this->to->format('Ymd');
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }
}

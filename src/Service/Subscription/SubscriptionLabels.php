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

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words and colours the subscription screens show for a state, a period and an
 * installment: one place, so that the list, the detail and the customer sheet say the same.
 */
final readonly class SubscriptionLabels
{
    private const STATUS_COLORS = [
        'trial' => '#0d6efd',
        'active' => '#198754',
        'payment_failed' => '#dc3545',
        'suspended' => '#b54708',
        'paused' => '#6c757d',
        'cancelled' => '#6f42c1',
        'ended' => '#495057',
    ];

    private const FALLBACK_COLOR = '#6c757d';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function status(string $status): string
    {
        return match ($status) {
            'trial' => $this->translator->trans('Trial'),
            'active' => $this->translator->trans('Active'),
            'payment_failed' => $this->translator->trans('Payment failed'),
            'suspended' => $this->translator->trans('Suspended'),
            'paused' => $this->translator->trans('Paused'),
            'cancelled' => $this->translator->trans('Cancelled'),
            'ended' => $this->translator->trans('Ended'),
            default => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return self::STATUS_COLORS[$status] ?? self::FALLBACK_COLOR;
    }

    /**
     * "Every month", "Every 3 weeks".
     */
    public function period(string $unit, int $count): string
    {
        $count = max(1, $count);

        if (1 === $count) {
            return match ($unit) {
                'week' => $this->translator->trans('Every week'),
                'month' => $this->translator->trans('Every month'),
                'quarter' => $this->translator->trans('Every quarter'),
                'year' => $this->translator->trans('Every year'),
                default => $unit,
            };
        }

        return match ($unit) {
            'week' => $this->translator->trans('Every %count% weeks', ['%count%' => $count]),
            'month' => $this->translator->trans('Every %count% months', ['%count%' => $count]),
            'quarter' => $this->translator->trans('Every %count% quarters', ['%count%' => $count]),
            'year' => $this->translator->trans('Every %count% years', ['%count%' => $count]),
            default => $unit,
        };
    }

    public function installmentState(string $state): string
    {
        return match ($state) {
            'paid' => $this->translator->trans('Paid'),
            'failed' => $this->translator->trans('Failed'),
            'pending' => $this->translator->trans('Pending'),
            default => $state,
        };
    }

    public function installmentColor(string $state): string
    {
        return match ($state) {
            'paid' => '#198754',
            'failed' => '#dc3545',
            default => '#6c757d',
        };
    }

    /**
     * A stored date, in the language of the administrator; a dash when there is none.
     */
    public function date(?string $stored, string $locale, bool $withTime = false): string
    {
        if (null === $stored || '' === $stored || str_starts_with($stored, '0000')) {
            return '-';
        }

        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE);
        $formatted = $formatter->format(new \DateTimeImmutable($stored));

        return false === $formatted ? $stored : $formatted;
    }

    /**
     * Who made a change, as the history writes it.
     */
    public function actor(string $type, ?string $adminLogin, ?int $adminId): string
    {
        return match ($type) {
            'customer' => $this->translator->trans('The customer'),
            'system' => $this->translator->trans('The shop'),
            'admin' => null !== $adminLogin
                ? $this->translator->trans('Administrator %login%', ['%login%' => $adminLogin])
                : $this->translator->trans('Administrator #%id%', ['%id%' => (int) $adminId]),
            default => $type,
        };
    }
}

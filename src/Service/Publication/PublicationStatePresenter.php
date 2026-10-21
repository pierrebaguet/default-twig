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

namespace BackOfficeDefaultTwigBundle\Service\Publication;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\CMS\Publication\PublicationService;
use Thelia\Domain\CMS\Publication\PublicationState;
use Thelia\Domain\CMS\Publication\PublicationStatus;
use Thelia\Model\Content;
use Thelia\Model\Folder;

/**
 * The publication state of a content or a folder as the back office shows it: a badge
 * (its variant and its label, with the date the state waits for) and the sentences that
 * tell the dates on the sheet. Null on a core without the publication.
 */
final readonly class PublicationStatePresenter
{
    public const array BADGE_VARIANTS = [
        'draft' => 'secondary',
        'scheduled' => 'info',
        'published' => 'success',
        'unpublished' => 'dark',
        'overdue' => 'warning',
    ];

    public function __construct(
        private ContentPublicationSupport $support,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
        private ?PublicationService $publication = null,
    ) {
    }

    public function statusOf(Content|Folder $row): ?PublicationStatus
    {
        if (null === $this->publication || !$this->support->isAvailable()) {
            return null;
        }

        return $this->publication->statusOf($row);
    }

    /**
     * @return array{state: string, badge: string, label: string}|null
     */
    public function badge(Content|Folder $row): ?array
    {
        $status = $this->statusOf($row);
        if (null === $status) {
            return null;
        }

        return [
            'state' => $status->state->value,
            'badge' => $status->isOverdue() ? 'overdue' : $status->state->value,
            'label' => $this->label($status),
        ];
    }

    public function label(PublicationStatus $status): string
    {
        $pendingDate = $status->pendingDate();

        return match ($status->state) {
            PublicationState::Draft => $this->translator->trans('Draft'),
            PublicationState::Unpublished => $this->translator->trans('Unpublished'),
            PublicationState::Scheduled => $this->translator->trans(
                $status->isOverdue() ? 'Scheduled for %date%, date passed' : 'Scheduled for %date%',
                ['%date%' => $this->date($status->publishAt)],
            ),
            PublicationState::Published => null === $pendingDate
                ? $this->translator->trans('Published')
                : $this->translator->trans(
                    $status->isOverdue() ? 'Published until %date%, date passed' : 'Published until %date%',
                    ['%date%' => $this->date($pendingDate)],
                ),
        };
    }

    /**
     * The dates of the state, one sentence each, for the sheet.
     *
     * @return list<string>
     */
    public function details(PublicationStatus $status): array
    {
        $details = [match ($status->state) {
            PublicationState::Published => null === $status->publishAt
                ? $this->translator->trans('Online.')
                : $this->translator->trans('Online since %date%', ['%date%' => $this->date($status->publishAt)]),
            PublicationState::Scheduled => $this->translator->trans('Goes online on %date%', ['%date%' => $this->date($status->publishAt)]),
            PublicationState::Unpublished => $this->translator->trans('Offline since %date%', ['%date%' => $this->date($status->unpublishAt)]),
            PublicationState::Draft => $this->translator->trans('Not online yet, and no publication date.'),
        }];

        if (!$status->isUnpublished() && null !== $status->unpublishAt) {
            $details[] = $this->translator->trans('Goes offline on %date%', ['%date%' => $this->date($status->unpublishAt)]);
        }

        return $details;
    }

    public function date(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '-';
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? \Locale::getDefault();
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
        $formatted = $formatter->format($date);

        return false === $formatted ? $date->format('Y-m-d H:i') : $formatted;
    }
}

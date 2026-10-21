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

use BackOfficeDefaultTwigBundle\Form\Publication\PublicationType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\CMS\Publication\EmptyBodyDetector;
use Thelia\Domain\CMS\Publication\Preview\PublicationPreview;
use Thelia\Domain\CMS\Publication\Preview\PublicationPreviewLinkGenerator;
use Thelia\Domain\CMS\Publication\PublicationAction;
use Thelia\Domain\CMS\Publication\PublicationState;
use Thelia\Domain\CMS\Publication\PublicationStatus;
use Thelia\Model\Content;
use Thelia\Model\ContentI18nQuery;
use Thelia\Model\Folder;
use Thelia\Model\LangQuery;

/**
 * What the publication block of a content or folder sheet shows: the state and its
 * dates, the gestures the state takes, the signed preview link, and for a content the
 * languages whose body is empty. Null on a core without the publication, where the
 * sheet keeps its online checkbox and its plain preview link.
 */
final readonly class PublicationPanelBuilder
{
    /**
     * Which gesture asks for which date. The block shows a date field only while a
     * gesture that reads it is chosen.
     */
    public const array FIELD_ACTIONS = [
        'publish_at' => ['schedule'],
        'unpublish_at' => ['publish', 'schedule', 'schedule_unpublication'],
    ];

    public function __construct(
        private ContentPublicationSupport $support,
        private PublicationStatePresenter $presenter,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private ?PublicationPreviewLinkGenerator $previewLinks = null,
    ) {
    }

    /**
     * The gesture form of a row, offering the gestures its current state takes: the
     * same form validates the submission, so a gesture the state does not take is refused.
     */
    public function form(Content|Folder $row): ?FormInterface
    {
        $status = $this->presenter->statusOf($row);
        if (null === $status) {
            return null;
        }

        $isContent = $row instanceof Content;

        return $this->formFactory->createNamed(
            $isContent ? 'thelia_content_publication' : 'thelia_folder_publication',
            PublicationType::class,
            [
                'action' => array_values($this->actions($status))[0] ?? null,
                'publish_at' => $status->isScheduled() ? $status->publishAt?->format(PublicationType::DATE_FORMAT) : null,
                'unpublish_at' => $status->isUnpublished() ? null : $status->unpublishAt?->format(PublicationType::DATE_FORMAT),
            ],
            [
                'actions' => $this->actions($status),
                'csrf_token_id' => $isContent ? 'admin.content.publication' : 'admin.folder.publication',
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(Content|Folder $row): ?array
    {
        $status = $this->presenter->statusOf($row);
        $form = $this->form($row);
        if (null === $status || null === $form) {
            return null;
        }

        $isContent = $row instanceof Content;

        return [
            'type' => $isContent ? 'content' : 'folder',
            'state' => $status->state->value,
            'badge_variant' => PublicationStatePresenter::BADGE_VARIANTS[$status->isOverdue() ? 'overdue' : $status->state->value],
            'label' => $this->presenter->label($status),
            'details' => $this->presenter->details($status),
            'overdue' => $status->isOverdue(),
            'form' => $form->createView(),
            'action_url' => $isContent
                ? $this->urls->generate('admin.content.publication', ['content_id' => (int) $row->getId()])
                : $this->urls->generate('admin.folders.publication', ['folder_id' => (int) $row->getId()]),
            'field_actions' => self::FIELD_ACTIONS,
            'date_placeholder' => 'YYYY-MM-DD HH:MM',
            'empty_body' => $isContent ? $this->emptyBodyLanguages($row) : null,
        ];
    }

    /**
     * The signed link to the page of the row, whatever its state, and when it expires.
     *
     * @return array{url: string, expires_at: string}|null
     */
    public function previewLink(Content|Folder $row): ?array
    {
        if (null === $this->previewLinks || !$this->support->isAvailable()) {
            return null;
        }

        $link = $this->previewLinks->generate(PublicationPreview::of(
            $row instanceof Content ? PublicationPreview::CONTENT : PublicationPreview::FOLDER,
            (int) $row->getId(),
        ));

        return ['url' => $link->url, 'expires_at' => $this->presenter->date($link->expiresAt)];
    }

    /**
     * @return array<string, string> label => action value, in the order the block offers them
     */
    private function actions(PublicationStatus $status): array
    {
        $actions = match ($status->state) {
            PublicationState::Draft, PublicationState::Unpublished => [
                PublicationAction::Publish->value => 'Publish now',
                PublicationAction::Schedule->value => 'Schedule the publication',
            ],
            PublicationState::Scheduled => [
                PublicationAction::Publish->value => 'Publish now',
                PublicationAction::Schedule->value => 'Change the publication date',
                PublicationAction::Cancel->value => 'Cancel the scheduled publication (back to draft)',
            ],
            PublicationState::Published => array_merge(
                [
                    PublicationAction::Unpublish->value => 'Unpublish now',
                    PublicationAction::ScheduleUnpublication->value => null === $status->unpublishAt ? 'Schedule the withdrawal' : 'Change the withdrawal date',
                ],
                null === $status->unpublishAt ? [] : [PublicationAction::Cancel->value => 'Cancel the scheduled withdrawal'],
            ),
        };

        $choices = [];
        foreach ($actions as $value => $label) {
            $choices[$this->translator->trans($label)] = $value;
        }

        return $choices;
    }

    /**
     * The active languages whose body is empty, which a content shows in that language
     * with no text. Only the default language stops the publication; the others are
     * pointed out without blocking.
     *
     * @return array{languages: list<string>, default_language_empty: bool}
     */
    private function emptyBodyLanguages(Content $content): array
    {
        $bodies = [];
        foreach (ContentI18nQuery::create()->filterById((int) $content->getId())->find() as $translation) {
            $bodies[(string) $translation->getLocale()] = $translation->getDescription();
        }

        $detector = new EmptyBodyDetector();
        $languages = [];
        $defaultEmpty = false;
        foreach (LangQuery::create()->filterByActive(true)->orderByPosition()->find() as $lang) {
            if (!$detector->isEmpty($bodies[(string) $lang->getLocale()] ?? null)) {
                continue;
            }

            $languages[] = (string) $lang->getTitle();
            $defaultEmpty = $defaultEmpty || 1 === (int) $lang->getByDefault();
        }

        return ['languages' => $languages, 'default_language_empty' => $defaultEmpty];
    }
}

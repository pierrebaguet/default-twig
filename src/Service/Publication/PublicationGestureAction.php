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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Content\ContentUpdatePublicationEvent;
use Thelia\Core\Event\Folder\FolderUpdatePublicationEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\CMS\Publication\Exception\PublicationException;
use Thelia\Domain\CMS\Publication\PublicationRequest;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Content;
use Thelia\Model\Folder;

/**
 * Applies the gesture the publication block of a sheet posts: permission to update,
 * the form (CSRF token, a gesture the current state takes), then the publication event
 * of the core. A gesture the core refuses (empty body, dates in the wrong order or in
 * the past) writes nothing and comes back as a danger message, translated, like a form
 * the validation refuses; any other failure is logged and shown as a plain message,
 * never as the text of the exception. The user lands back on the sheet either way.
 */
final readonly class PublicationGestureAction
{
    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormValidator $validator,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
        private PublicationStatePresenter $presenter,
    ) {
    }

    public function apply(string $resource, Content|Folder $row, FormInterface $form, string $sheetUrl): Response
    {
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $data = $this->validator->validate($form)->getData() ?? [];
            $action = (string) ($data['action'] ?? '');
            $status = $this->presenter->statusOf($row);
            $request = PublicationRequest::fromStrings(
                $action,
                $this->date('publish_at', $action, $data, $status?->isScheduled() ? $status->publishAt : null),
                $this->date('unpublish_at', $action, $data, $status?->isUnpublished() ? null : $status?->unpublishAt),
            );

            if ($row instanceof Content) {
                $event = new ContentUpdatePublicationEvent($row, $request);
                $this->events->dispatch($event, TheliaEvents::CONTENT_UPDATE_PUBLICATION);
            } else {
                $event = new FolderUpdatePublicationEvent($row, $request);
                $this->events->dispatch($event, TheliaEvents::FOLDER_UPDATE_PUBLICATION);
            }

            $status = $event->getStatus();
            $this->adminLogger->log($resource, AccessManager::UPDATE, \sprintf('Publication of %d: %s', (int) $row->getId(), $request->action->value), (int) $row->getId());
            $this->flash('success', $this->translator->trans('Publication updated: %state%', [
                '%state%' => null === $status ? '' : $this->presenter->label($status),
            ]));
        } catch (PublicationException $exception) {
            // The core wrote nothing, and words its message for the user in their language.
            $this->flash('danger', $exception->getMessage());
        } catch (FormValidationException $exception) {
            // The form words its first error for the user (expired token, gesture not offered).
            $this->errorRenderer->setup($this->translator->trans('Publication'), $exception->getMessage(), $form, $exception);
        } catch (\Throwable $exception) {
            // Not a refusal of the core: the text of the exception is for the log, not the user.
            $this->logger->error('Publication gesture failed.', ['exception' => $exception, 'resource' => $resource, 'id' => (int) $row->getId()]);
            $this->flash('danger', $this->translator->trans('The publication could not be updated. See the system log for the details.'));
        }

        return new RedirectResponse($sheetUrl);
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getMainRequest()?->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * The date a field carries, only when the gesture reads it: a field the block hides for
     * that gesture may still hold an old or unreadable value, which must neither refuse the
     * gesture nor be written. A date left as the block filled it in, to the minute, stands
     * for the date the row has, seconds included, so moving the other date leaves it as it was.
     *
     * @param array<string, mixed> $data
     */
    private function date(string $field, string $action, array $data, ?\DateTimeInterface $current): ?string
    {
        if (!\in_array($action, PublicationPanelBuilder::FIELD_ACTIONS[$field], true)) {
            return null;
        }

        $value = $data[$field] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        if (null !== $current && trim($value) === $current->format(PublicationType::DATE_FORMAT)) {
            return $current->format('Y-m-d H:i:s');
        }

        return $value;
    }
}

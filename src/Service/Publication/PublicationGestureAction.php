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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
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
use Thelia\Model\Content;
use Thelia\Model\Folder;

/**
 * Applies the gesture the publication block of a sheet posts: permission to update,
 * the form (CSRF token, a gesture the current state takes), then the publication event
 * of the core. A gesture the core refuses (empty body, dates in the wrong order or in
 * the past) writes nothing and comes back as a danger message, translated; the user
 * lands back on the sheet either way.
 */
final readonly class PublicationGestureAction
{
    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormValidator $validator,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
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
            $request = PublicationRequest::fromStrings(
                (string) ($data['action'] ?? ''),
                $this->stringOrNull($data['publish_at'] ?? null),
                $this->stringOrNull($data['unpublish_at'] ?? null),
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
            // The core wrote nothing. The body guard words its message for the user already;
            // the date errors are English sentences the theme translates.
            $this->flash('danger', $this->translator->trans($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup($this->translator->trans('Publication'), $exception->getMessage(), $form, $exception);
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

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }
}

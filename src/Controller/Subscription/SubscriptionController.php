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

namespace BackOfficeDefaultTwigBundle\Controller\Subscription;

use BackOfficeDefaultTwigBundle\Repository\SubscriptionRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Admin\CurrentAdministrator;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionActions;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionDetailContextBuilder;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilters;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionSupport;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Model\SubscriptionQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The subscriptions of the shop: the list, the detail with its installments and history, and
 * the four gestures of the merchant (cancel, pause, resume, reactivate), each a POST with its
 * token. The state machine of the core alone decides whether a gesture is allowed; its refusal
 * comes back as a message.
 *
 * Every route answers 404 on a core without the subscriptions.
 */
final class SubscriptionController
{
    private const RESOURCE = SubscriptionSupport::RESOURCE;
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/subscription/list.html.twig';
    private const DETAIL_TEMPLATE = '@BackOfficeDefaultTwig/subscription/detail.html.twig';
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionFilterPresenter $filterPresenter,
        private readonly SubscriptionListRowPresenter $rowPresenter,
        private readonly SubscriptionDetailContextBuilder $detailContext,
        private readonly SubscriptionActions $actions,
        private readonly CurrentAdministrator $administrator,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
    ) {
    }

    #[Route('/admin/subscriptions', name: 'admin.subscription.list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->requireTheCore();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $filters = SubscriptionFilters::fromRequest($request);
        $locale = $request->getLocale();
        $page = max(1, (int) $request->query->get('page', 1));
        $counts = $this->subscriptions->countByStatus();
        $paginated = $this->subscriptions->findPaginated($filters, $locale, $page, self::PAGE_SIZE);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->rowPresenter->presentAll($paginated['rows'], $locale),
            'total' => $paginated['total'],
            'pages' => $paginated['lastPage'],
            'current_page' => min($page, $paginated['lastPage']),
            'filters' => $this->filterPresenter->present($filters, $counts, $locale),
            'query_params' => $filters->toQueryParams(),
            'counters' => [
                'active' => $counts['active'] ?? 0,
                'payment_failed' => $counts['payment_failed'] ?? 0,
            ],
        ]));
    }

    #[Route('/admin/subscriptions/{subscription_id}', name: 'admin.subscription.view', methods: ['GET'], requirements: ['subscription_id' => '\d+'])]
    public function detail(Request $request, int $subscription_id): Response
    {
        $this->requireTheCore();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $summary = $this->subscriptions->findSummary($subscription_id, $locale);

        if (null === $summary) {
            $this->flash->add($request, 'warning', $this->translator->trans('This subscription does not exist.'));

            return new RedirectResponse($this->urls->generate(SubscriptionFilterPresenter::LIST_ROUTE));
        }

        $context = $this->detailContext->build($summary, $locale, max(1, (int) $request->query->get('installments_page', 1)));

        return new Response($this->twig->render(self::DETAIL_TEMPLATE, $context + [
            'token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route(
        '/admin/subscriptions/{subscription_id}/{action}',
        name: 'admin.subscription.action',
        methods: ['POST'],
        requirements: ['subscription_id' => '\d+', 'action' => 'cancel|pause|resume|reactivate'],
    )]
    public function act(Request $request, int $subscription_id, string $action): Response
    {
        $this->requireTheCore();

        // The right comes first: nothing about the subscription is said before it is checked.
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate('admin.subscription.view', ['subscription_id' => $subscription_id]));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $subscription = SubscriptionQuery::create()->findPk($subscription_id);

        if (null === $subscription) {
            $this->flash->add($request, 'warning', $this->translator->trans('This subscription does not exist.'));

            return new RedirectResponse($this->urls->generate(SubscriptionFilterPresenter::LIST_ROUTE));
        }

        $outcome = $this->actions->perform($action, $subscription, $this->administrator->id());
        $this->flash->add($request, $outcome['success'] ? 'success' : 'danger', $outcome['message']);

        if ($outcome['success']) {
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Subscription %s: %s', $subscription->getRef(), $action), $subscription_id);
        }

        return $back;
    }

    private function requireTheCore(): void
    {
        if (!$this->support->isAvailable()) {
            throw new NotFoundHttpException();
        }
    }
}

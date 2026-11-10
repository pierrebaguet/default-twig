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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Subscription\InvalidSubscriptionPlanException;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionPlanRequestReader;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionPlanWriter;
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
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ProductQuery;
use Thelia\Model\SubscriptionPlan;
use Thelia\Model\SubscriptionPlanQuery;
use Thelia\Tools\TokenProvider;

/**
 * The subscription plans of a product, written from the plans tab of the product sheet.
 *
 * The plans belong to the catalogue: every route asks the update right on the product, not
 * the right on the subscriptions (a merchant who sets up what a product sells by subscription
 * does not need to see the subscriptions of the customers). On a core without the
 * subscriptions every route answers 404.
 */
#[Route('/admin/products/subscription-plans', name: 'admin.products.subscription-plans.')]
final readonly class ProductSubscriptionPlanController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const EDIT_ROUTE = 'admin.products.update';
    private const TAB = 'subscription-plans';

    public function __construct(
        private SubscriptionSupport $support,
        private AdminAccessChecker $access,
        private AdminLogger $adminLogger,
        private AdminFlash $flash,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private SubscriptionPlanRequestReader $reader,
        private SubscriptionPlanWriter $writer,
    ) {
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->change($request, function (int $productId, ?SubscriptionPlan $plan) use ($request): ?string {
            try {
                $saved = $this->writer->save($productId, $plan, $this->reader->read($request));
            } catch (InvalidSubscriptionPlanException $refusal) {
                $this->flash->add($request, 'danger', $refusal->getMessage());

                return null === $plan ? 'new' : (string) $plan->getId();
            }

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Subscription plan %d saved on product %d', $saved->getId(), $productId), $productId);
            $this->flash->add($request, 'success', $this->translator->trans('The plan was saved.'));

            return null;
        }, planRequired: false);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->change($request, function (int $productId, ?SubscriptionPlan $plan) use ($request): ?string {
            $this->writer->delete($plan);
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Subscription plan deleted on product %d', $productId), $productId);
            $this->flash->add($request, 'success', $this->translator->trans('The plan was deleted. The subscriptions already taken on it go on unchanged.'));

            return null;
        });
    }

    #[Route('/visibility', name: 'visibility', methods: ['POST'])]
    public function visibility(Request $request): Response
    {
        return $this->change($request, function (int $productId, ?SubscriptionPlan $plan): ?string {
            $this->writer->toggleVisibility($plan);

            return null;
        });
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request): Response
    {
        return $this->change($request, function (int $productId, ?SubscriptionPlan $plan) use ($request): ?string {
            $this->writer->move($plan, 'up' === $request->request->get('direction'));

            return null;
        });
    }

    /**
     * @param \Closure(int, ?SubscriptionPlan): ?string $change what to do once the right, the token, the product and the plan are checked; the plan to leave open in the form, if any
     */
    private function change(Request $request, \Closure $change, bool $planRequired = true): Response
    {
        if (!$this->support->isAvailable()) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $productId = (int) $request->request->get('product_id', 0);

        try {
            // The token is read from the request body only, never from the URL.
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $this->back($productId);
        }

        if (null === ProductQuery::create()->findPk($productId)) {
            throw new NotFoundHttpException();
        }

        $plan = null;
        $planId = (int) $request->request->get('plan_id', 0);

        if ($planId > 0 || $planRequired) {
            // Read by the identifier of the plan and of the product: another product's plan is not reachable.
            $plan = SubscriptionPlanQuery::create()->filterByProductId($productId)->findPk($planId);

            if (null === $plan) {
                throw new NotFoundHttpException();
            }
        }

        return $this->back($productId, $change($productId, $plan));
    }

    private function back(int $productId, ?string $openForm = null): RedirectResponse
    {
        $parameters = ['product_id' => $productId, 'current_tab' => self::TAB];

        if (null !== $openForm) {
            $parameters['plan'] = $openForm;
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, $parameters));
    }
}

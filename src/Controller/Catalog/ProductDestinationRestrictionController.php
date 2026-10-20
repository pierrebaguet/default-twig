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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductDestinationRestrictionContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Tools\TokenProvider;

/**
 * Puts a product in a destination restriction group, or takes it out, from the product
 * sheet. The facade refuses an unknown group or product: the refusal comes back as an
 * error message on the sheet. On a core without the destination restrictions both
 * routes answer 404.
 */
#[Route('/admin/products', name: 'admin.products.')]
final readonly class ProductDestinationRestrictionController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const EDIT_ROUTE = 'admin.products.update';

    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
        private TokenProvider $tokens,
        private UrlGeneratorInterface $urls,
        // Null on a core that predates the destination restrictions: the class is not there to autowire.
        private ?DestinationRestrictionFacade $restrictions = null,
    ) {
    }

    #[Route('/destination-restriction/attach', name: 'destination-restriction.attach', methods: ['POST'])]
    public function attach(Request $request): Response
    {
        return $this->change(
            $request,
            'Product destination restriction attachment',
            fn (int $groupId, int $productId) => $this->facade()->attachProduct($groupId, $productId),
            'Destination restriction %group attached to product %product',
        );
    }

    #[Route('/destination-restriction/detach', name: 'destination-restriction.detach', methods: ['POST'])]
    public function detach(Request $request): Response
    {
        return $this->change(
            $request,
            'Product destination restriction detachment',
            fn (int $groupId, int $productId) => $this->facade()->detachProduct($groupId, $productId),
            'Destination restriction %group detached from product %product',
        );
    }

    /**
     * @param callable(int, int): mixed $change
     */
    private function change(Request $request, string $actionLabel, callable $change, string $logMessage): Response
    {
        // A core that predates the destination restrictions has nothing to change.
        if (!ProductDestinationRestrictionContext::coreHasDestinationRestrictions()) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $productId = (int) $request->request->get('product_id', 0);
        $groupId = (int) $request->request->get('restriction_id', 0);

        try {
            // The token is read from the request body only, never from the URL.
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $change($groupId, $productId);
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, strtr($logMessage, ['%group' => (string) $groupId, '%product' => (string) $productId]), $productId);
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup($actionLabel, $exception->getMessage(), null, $exception);
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId, 'current_tab' => 'general']));
    }

    private function facade(): DestinationRestrictionFacade
    {
        return $this->restrictions ?? throw new NotFoundHttpException();
    }
}

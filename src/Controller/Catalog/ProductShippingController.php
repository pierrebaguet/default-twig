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
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductShippingContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Product\ProductFacade;
use Thelia\Tools\TokenProvider;

/**
 * Keeps a product from a carrier, or lets it use the carrier again. The facade
 * refuses a module that is not an active delivery module: the refusal comes
 * back as an error message on the product sheet.
 */
#[Route('/admin/products', name: 'admin.products.')]
final readonly class ProductShippingController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const EDIT_ROUTE = 'admin.products.update';

    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
        private TokenProvider $tokens,
        private ProductFacade $products,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/carrier/exclude', name: 'carrier.exclude', methods: ['POST'])]
    public function exclude(Request $request): Response
    {
        return $this->change(
            $request,
            'Product carrier exclusion',
            fn (int $productId, int $moduleId) => $this->products->excludeDeliveryModule($productId, $moduleId),
            'Carrier %module excluded for product %product',
        );
    }

    #[Route('/carrier/allow', name: 'carrier.allow', methods: ['POST'])]
    public function allow(Request $request): Response
    {
        return $this->change(
            $request,
            'Product carrier allowance',
            fn (int $productId, int $moduleId) => $this->products->allowDeliveryModule($productId, $moduleId),
            'Carrier %module allowed again for product %product',
        );
    }

    /**
     * @param callable(int, int): void $change
     */
    private function change(Request $request, string $actionLabel, callable $change, string $logMessage): Response
    {
        // A core that predates the shipping rules of a product has nothing to change.
        if (!ProductShippingContext::coreHasShippingRules()) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $productId = (int) $request->request->get('product_id', 0);
        $moduleId = (int) $request->request->get('module_id', 0);

        try {
            // The token is read from the request body only, never from the URL.
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $change($productId, $moduleId);
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, strtr($logMessage, ['%module' => (string) $moduleId, '%product' => (string) $productId]), $productId);
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup($actionLabel, $exception->getMessage(), null, $exception);
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId, 'current_tab' => 'general']));
    }
}

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

namespace BackOfficeDefaultTwigBundle\Controller\Customer;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerCartsProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CustomerQuery;
use Twig\Environment;

/**
 * The carts section of the customer sheet, loaded when it is opened. Same access check as
 * the sheet itself.
 */
#[Route('/admin', name: 'admin.')]
final readonly class CustomerCartsController
{
    public function __construct(
        private AdminAccessChecker $access,
        private CustomerCartsProvider $carts,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/customer/carts', name: 'customer.carts', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::CUSTOMER, [], AccessManager::VIEW)) {
            return $denied;
        }

        $customer = CustomerQuery::create()->findPk((int) $request->query->get('customer_id', 0));
        if ($customer === null) {
            return new Response($this->translator->trans('This customer does not exist.'), Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/customer/_carts_section.html.twig', [
            'carts' => $this->carts->compute((int) $customer->getId(), $request->getLocale(), new \DateTimeImmutable()),
        ]));
    }
}

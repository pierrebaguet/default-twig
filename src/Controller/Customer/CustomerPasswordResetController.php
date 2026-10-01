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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerPasswordResetLinkSender;
use BackOfficeDefaultTwigBundle\Service\Customer\PasswordResetLinkRefused;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Mailer\Exception\EmailNotSentException;
use Thelia\Model\CustomerQuery;
use Thelia\Tools\TokenProvider;

/**
 * "Send a password reset link" on the customer sheet. The anti-CSRF token of the admin
 * session is checked, the request counts against a per-customer rate limit, and every
 * link sent leaves a line in the admin log.
 */
#[Route('/admin', name: 'admin.')]
final readonly class CustomerPasswordResetController
{
    private const RESOURCE = AdminResources::CUSTOMER;
    private const LIST_ROUTE = 'admin.customers';
    private const EDIT_ROUTE = 'admin.customer.update.view';

    public function __construct(
        private AdminAccessChecker $access,
        private AdminLogger $adminLogger,
        private CustomerPasswordResetLinkSender $sender,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/customer/password-reset-link', name: 'customer.password_reset_link', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $customerId = (int) $request->request->get('customer_id', 0);
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $back = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash($request, 'danger', $this->translator->trans('The form has expired. Reload the page and try again.'));

            return $back;
        }

        try {
            $this->sender->send($customer);
        } catch (PasswordResetLinkRefused $refused) {
            $this->flash($request, 'danger', $this->translator->trans($refused->getMessage()));

            return $back;
        } catch (EmailNotSentException $exception) {
            // The message names the mail and the kind of failure only. The raw reason names the
            // recipient and may hold transport details: the mailer already logs it, stripped
            // of credentials.
            $this->logger->error('The password reset link could not be sent', ['reason' => $exception->getMessage(), 'customer_id' => $customerId]);
            $this->flash($request, 'danger', $this->translator->trans('The password reset link could not be sent: check the mail settings of the shop.'));

            return $back;
        }

        // No name nor address in the message: admin_log outlives an anonymization.
        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Password reset link sent to customer ID %d', $customerId),
            $customerId,
        );
        $this->flash($request, 'success', $this->translator->trans('A password reset link was sent to %email%.', ['%email%' => (string) $customer->getEmail()]));

        return $back;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}

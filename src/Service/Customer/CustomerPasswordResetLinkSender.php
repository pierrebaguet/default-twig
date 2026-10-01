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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Domain\Customer\Service\CustomerAnonymizer;
use Thelia\Domain\Customer\Service\PasswordResetService;
use Thelia\Mailer\Exception\EmailNotSentException;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\Customer;

/**
 * Mails a customer the link to choose a new password, on an administrator's request.
 *
 * The front-office request (PasswordResetService::requestResetLink()) is not reused: it
 * answers the same way whatever happens, so that a visitor cannot tell whether an address
 * has an account, and it spends a budget keyed on the caller's address. An administrator
 * has to know whether the mail left, and must not spend the customer's own budget. The
 * token and the message are the core ones, so the link works the same.
 */
final readonly class CustomerPasswordResetLinkSender
{
    public function __construct(
        private PasswordResetService $passwordReset,
        private MailerFactory $mailer,
        #[Autowire(service: 'limiter.admin_customer_password_reset')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * @throws PasswordResetLinkRefused when the customer cannot receive a link now
     * @throws EmailNotSentException    when the mail did not leave
     */
    public function send(Customer $customer): void
    {
        // A guest row holds no password: there is nothing to reset, and a link would set
        // a password on the row that carries somebody's guest orders.
        if ((bool) $customer->getIsGuest()) {
            throw new PasswordResetLinkRefused('This customer ordered without an account: there is no password to reset.');
        }

        if ((string) $customer->getEmail() === '') {
            throw new PasswordResetLinkRefused('This customer has no email address.');
        }

        if (str_ends_with((string) $customer->getEmail(), '@'.CustomerAnonymizer::ANONYMIZED_EMAIL_DOMAIN)) {
            throw new PasswordResetLinkRefused('This customer was anonymized: there is nobody to send a link to.');
        }

        if (!$this->limiter->create('customer-'.$customer->getId())->consume()->isAccepted()) {
            throw new PasswordResetLinkRefused('A password reset link was already sent to this customer several times in the last hour.');
        }

        $this->mailer->sendEmailToCustomerOrFail('lost_password', $customer, [
            'token' => $this->passwordReset->createToken($customer),
            'tokenLifetimeInMinutes' => intdiv($this->passwordReset->getLinkLifetimeInSeconds(), 60),
        ]);
    }
}

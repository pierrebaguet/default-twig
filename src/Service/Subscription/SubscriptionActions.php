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

namespace BackOfficeDefaultTwigBundle\Service\Subscription;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Subscription\Enum\ActorType;
use Thelia\Domain\Subscription\Enum\SubscriptionStatus;
use Thelia\Domain\Subscription\Exception\SubscriptionException;
use Thelia\Domain\Subscription\SubscriptionManager;
use Thelia\Model\Subscription;

/**
 * What an administrator does to a subscription. Every gesture goes through the manager of
 * the core, which is the only one to decide whether the state allows it and to write the
 * history; this class only says which gestures to offer and relays the refusal as a message.
 *
 * The manager is an optional argument: on a core without the subscriptions it does not
 * exist, and nothing here is called.
 */
final readonly class SubscriptionActions
{
    public const CANCEL = 'cancel';
    public const PAUSE = 'pause';
    public const RESUME = 'resume';
    public const REACTIVATE = 'reactivate';

    public const ALL = [self::CANCEL, self::PAUSE, self::RESUME, self::REACTIVATE];

    public function __construct(
        private TranslatorInterface $translator,
        private ?SubscriptionManager $manager = null,
    ) {
    }

    /**
     * The gestures the state allows, in the order they are offered. A subscription that
     * cannot be paused by its plan is not offered the pause.
     *
     * @return list<string>
     */
    public function allowedFor(string $status, bool $pausable): array
    {
        $current = SubscriptionStatus::tryFrom($status);

        if (null === $current) {
            return [];
        }

        $allowed = [];

        if ($pausable && $current->canBecome(SubscriptionStatus::Paused)) {
            $allowed[] = self::PAUSE;
        }

        if (SubscriptionStatus::Paused === $current) {
            $allowed[] = self::RESUME;
        }

        if (\in_array($current, [SubscriptionStatus::Suspended, SubscriptionStatus::PaymentFailed], true)) {
            $allowed[] = self::REACTIVATE;
        }

        if ($current->canBecome(SubscriptionStatus::Cancelled)) {
            $allowed[] = self::CANCEL;
        }

        return $allowed;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function perform(string $action, Subscription $subscription, ?int $administratorId): array
    {
        if (null === $this->manager || !\in_array($action, self::ALL, true)) {
            throw new \LogicException('The subscriptions are not available.');
        }

        try {
            match ($action) {
                self::CANCEL => $this->manager->cancel($subscription, ActorType::Admin, $administratorId),
                self::PAUSE => $this->manager->pause($subscription, ActorType::Admin, $administratorId),
                self::RESUME => $this->manager->resume($subscription, ActorType::Admin, $administratorId),
                self::REACTIVATE => $this->manager->reactivate($subscription, ActorType::Admin, $administratorId),
            };
        } catch (SubscriptionException $refusal) {
            // SubscriptionActionRefusedException and SubscriptionTransitionRefusedException: the
            // state or the plan does not allow it, or the subscription moved since it was read.
            return ['success' => false, 'message' => $this->translator->trans('The subscription %ref% was left as it is: %reason%', [
                '%ref%' => (string) $subscription->getRef(),
                '%reason%' => $refusal->getMessage(),
            ])];
        }

        return ['success' => true, 'message' => $this->success($action, (string) $subscription->getRef())];
    }

    private function success(string $action, string $ref): string
    {
        return match ($action) {
            self::CANCEL => $this->translator->trans('The subscription %ref% is cancelled: it is served until the end of its paid period.', ['%ref%' => $ref]),
            self::PAUSE => $this->translator->trans('The subscription %ref% is paused.', ['%ref%' => $ref]),
            self::RESUME => $this->translator->trans('The subscription %ref% is active again.', ['%ref%' => $ref]),
            default => $this->translator->trans('The failed installment of the subscription %ref% is being charged again.', ['%ref%' => $ref]),
        };
    }
}

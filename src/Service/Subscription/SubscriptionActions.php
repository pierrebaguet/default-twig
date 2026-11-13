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
use Thelia\Domain\Subscription\Enum\SubscriptionAction;
use Thelia\Domain\Subscription\Enum\SubscriptionStatus;
use Thelia\Domain\Subscription\Exception\SubscriptionException;
use Thelia\Domain\Subscription\SubscriptionLifecycle;
use Thelia\Model\Subscription;

/**
 * What an administrator does to a subscription. Every gesture goes through the lifecycle of
 * the core, which is the only one to decide whether the state allows it and to write the
 * history; this class only says which gestures to offer and relays the refusal as a message.
 *
 * The lifecycle is an optional argument: on a core without the subscriptions it does not
 * exist, and nothing here is called.
 */
final readonly class SubscriptionActions
{
    // The values of SubscriptionAction, written out: a constant expression reading a class of the
    // core would be evaluated on a core without the subscriptions.
    public const CANCEL = 'cancel';
    public const PAUSE = 'pause';
    public const RESUME = 'resume';
    public const REACTIVATE = 'reactivate';

    public const ALL = [self::CANCEL, self::PAUSE, self::RESUME, self::REACTIVATE];

    public function __construct(
        private TranslatorInterface $translator,
        private ?SubscriptionLifecycle $lifecycle = null,
    ) {
    }

    /**
     * The gestures the state allows, in the order they are offered: the rule of the core
     * (SubscriptionAction), less the change of payment method, which is the customer's.
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

        foreach (SubscriptionAction::allowedIn($current, $pausable) as $action) {
            if (\in_array($action->value, self::ALL, true)) {
                $allowed[] = $action->value;
            }
        }

        return $allowed;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function perform(string $action, Subscription $subscription, ?int $administratorId): array
    {
        if (null === $this->lifecycle || !\in_array($action, self::ALL, true)) {
            throw new \LogicException('The subscriptions are not available.');
        }

        try {
            match ($action) {
                self::CANCEL => $this->lifecycle->cancel($subscription, ActorType::Admin, $administratorId),
                self::PAUSE => $this->lifecycle->pause($subscription, ActorType::Admin, $administratorId),
                self::RESUME => $this->lifecycle->resume($subscription, ActorType::Admin, $administratorId),
                self::REACTIVATE => $this->lifecycle->reactivate($subscription, ActorType::Admin, $administratorId),
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

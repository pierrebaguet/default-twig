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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Subscription;

use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionActions;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Subscription\Enum\SubscriptionStatus;

final class SubscriptionActionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!(new SubscriptionSupport())->isAvailable()) {
            self::markTestSkipped('The core does not carry the subscriptions.');
        }
    }

    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function offers(): iterable
    {
        yield 'trial' => ['trial', true, ['cancel']];
        yield 'active, pausable' => ['active', true, ['pause', 'cancel']];
        yield 'active, not pausable' => ['active', false, ['cancel']];
        yield 'payment failed' => ['payment_failed', true, ['reactivate', 'cancel']];
        yield 'suspended' => ['suspended', false, ['reactivate', 'cancel']];
        yield 'paused' => ['paused', true, ['resume', 'cancel']];
        yield 'cancelled' => ['cancelled', true, []];
        yield 'ended' => ['ended', true, []];
        yield 'unknown' => ['whatever', true, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('offers')]
    public function testOnlyTheGesturesTheStateAllowsAreOffered(string $status, bool $pausable, array $expected): void
    {
        $translator = $this->createStub(TranslatorInterface::class);

        self::assertSame($expected, (new SubscriptionActions($translator))->allowedFor($status, $pausable));
    }

    public function testNothingIsOfferedThatTheMapOfTheCoreForbids(): void
    {
        $actions = new SubscriptionActions($this->createStub(TranslatorInterface::class));

        foreach (SubscriptionStatus::cases() as $status) {
            $offered = $actions->allowedFor($status->value, true);
            self::assertSame(\in_array('cancel', $offered, true), $status->canBecome(SubscriptionStatus::Cancelled), $status->value);
            self::assertSame(\in_array('pause', $offered, true), $status->canBecome(SubscriptionStatus::Paused), $status->value);
        }
    }
}

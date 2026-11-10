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

use BackOfficeDefaultTwigBundle\Service\Subscription\InvalidSubscriptionPlanException;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionPlanRequestReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionPlanRequestReaderTest extends TestCase
{
    private SubscriptionPlanRequestReader $reader;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->reader = new SubscriptionPlanRequestReader($translator);
    }

    /**
     * @param array<string, mixed> $over
     */
    private static function request(array $over = []): Request
    {
        return new Request([], $over + [
            'period_unit' => 'month', 'period_count' => '1', 'trial_days' => '0', 'installment_count' => '', 'commitment_count' => '',
            'title' => ['en_US' => ' Monthly box ', 'fr_FR' => ''], 'price' => ['1' => '9,90', '2' => ''], 'pausable' => '1',
        ]);
    }

    public function testAValidFormIsRead(): void
    {
        $input = $this->reader->read(self::request());

        self::assertSame('month', $input->periodUnit);
        self::assertSame(1, $input->periodCount);
        self::assertSame(0, $input->trialDays);
        self::assertNull($input->installmentCount);
        self::assertNull($input->commitmentCount);
        self::assertTrue($input->pausable);
        self::assertFalse($input->visible);
        self::assertSame(['en_US' => 'Monthly box'], $input->titles, 'The empty titles are left out and the others trimmed.');
        self::assertSame([1 => '9.90'], $input->prices, 'A decimal comma is read, an empty price is left out.');
    }

    public function testTheCountsAreRead(): void
    {
        $input = $this->reader->read(self::request(['installment_count' => '12', 'commitment_count' => '3', 'trial_days' => '14', 'period_count' => '2']));

        self::assertSame([12, 3, 14, 2], [$input->installmentCount, $input->commitmentCount, $input->trialDays, $input->periodCount]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedForms(): iterable
    {
        yield 'unknown unit' => [['period_unit' => 'decade']];
        yield 'zero period' => [['period_count' => '0']];
        yield 'negative trial' => [['trial_days' => '-1']];
        yield 'zero installments' => [['installment_count' => '0']];
        yield 'zero commitment' => [['commitment_count' => '0']];
        yield 'no title' => [['title' => ['en_US' => '  ']]];
        yield 'negative price' => [['price' => ['1' => '-2']]];
        yield 'price that is not a number' => [['price' => ['1' => 'free']]];
    }

    /**
     * @param array<string, mixed> $over
     */
    #[DataProvider('refusedForms')]
    public function testAFormTheApiWouldRefuseIsRefused(array $over): void
    {
        $this->expectException(InvalidSubscriptionPlanException::class);

        $this->reader->read(self::request($over));
    }
}

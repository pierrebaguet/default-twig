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

use BackOfficeDefaultTwigBundle\Repository\SubscriptionRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilterCatalog;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionFilters;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionLabels;
use BackOfficeDefaultTwigBundle\Service\Subscription\SubscriptionListRowPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionPresentersTest extends TestCase
{
    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        return $translator;
    }

    private function catalog(): SubscriptionFilterCatalog
    {
        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('findPlanOptions')->willReturn([['id' => 5, 'label' => 'Gold box (Travis)']]);

        return new SubscriptionFilterCatalog($subscriptions);
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '/'.$name.'?'.http_build_query($parameters));

        return $urls;
    }

    public function testTheRowCarriesTheStateTheAmountOfAnInstallmentAndEscapesWhatTheCustomerWrote(): void
    {
        $presenter = new SubscriptionListRowPresenter($this->urls(), $this->translator(), new MoneyFormatter(), new SubscriptionLabels($this->translator()));

        $row = $presenter->present([
            'id' => 5, 'ref' => 'SUB5', 'status' => 'payment_failed', 'quantity' => 2, 'unit_price' => 12.5, 'period_unit' => 'month', 'period_count' => 3,
            'currency_code' => 'EUR', 'customer_id' => 9, 'firstname' => '<b>Ada</b>', 'lastname' => 'L"ove', 'email' => 'a@example.com',
            'product_id' => 4, 'product_title' => 'Box & more', 'plan_title' => '', 'next_billing_at' => '2031-03-10 10:00:00', 'created_at' => '2031-01-10 10:00:00',
        ], 'en_US');

        self::assertSame('payment_failed', $row['status_code']);
        self::assertSame('Payment failed', $row['status']);
        self::assertSame('#dc3545', $row['status_color']);
        self::assertSame('Every 3 months', $row['plan']);
        self::assertStringContainsString('25.00', $row['amount'], 'Unit price times quantity.');
        self::assertSame('Mar 10, 2031', $row['next_billing']);
        self::assertStringNotContainsString('<b>', $row['customer_html']);
        self::assertStringContainsString('&lt;b&gt;Ada', $row['customer_html']);
        self::assertStringContainsString('Box &amp; more', $row['product_html']);
        self::assertStringContainsString('/admin.subscription.view?subscription_id=5', $row['ref_html']);
    }

    public function testADeletedProductIsSaidSo(): void
    {
        $presenter = new SubscriptionListRowPresenter($this->urls(), $this->translator(), new MoneyFormatter(), new SubscriptionLabels($this->translator()));

        $row = $presenter->present([
            'id' => 5, 'ref' => 'SUB5', 'status' => 'active', 'quantity' => 1, 'unit_price' => 1.0, 'period_unit' => 'week', 'period_count' => 1,
            'currency_code' => 'EUR', 'customer_id' => 9, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@example.com',
            'product_id' => null, 'product_title' => null, 'plan_title' => null, 'next_billing_at' => null, 'created_at' => null,
        ], 'en_US');

        self::assertStringContainsString('Product deleted', $row['product_html']);
        self::assertSame('-', $row['next_billing']);
    }

    public function testTheShortcutChipsCarryTheCountersAndTheActiveOneIsMarked(): void
    {
        $presenter = new SubscriptionFilterPresenter($this->urls(), $this->translator(), new SubscriptionLabels($this->translator()), $this->catalog());
        $filters = SubscriptionFilters::fromRequest(new Request(['status' => ['payment_failed'], 'q' => 'SUB']));

        $view = $presenter->present($filters, ['active' => 4, 'payment_failed' => 2, 'ended' => 1], 'en_US');

        $chips = array_column($view['quick_chips'], null, 'code');
        self::assertSame(7, $chips['all']['count']);
        self::assertSame(4, $chips['active']['count']);
        self::assertSame(2, $chips['payment_failed']['count']);
        self::assertTrue($chips['payment_failed']['active']);
        self::assertFalse($chips['all']['active']);
        self::assertStringContainsString('status%5B0%5D=active', $chips['active']['url']);
        self::assertStringContainsString('q=SUB', $chips['active']['url'], 'A shortcut keeps the other filters.');
    }

    public function testEachActiveFilterHasAChipThatRemovesIt(): void
    {
        $presenter = new SubscriptionFilterPresenter($this->urls(), $this->translator(), new SubscriptionLabels($this->translator()), $this->catalog());
        $filters = SubscriptionFilters::fromRequest(new Request(['status' => ['active'], 'q' => 'SUB', 'plan' => '5']));

        $chips = array_column($presenter->present($filters, [], 'en_US')['active_chips'], null, 'key');

        self::assertSame(['statuses', 'q', 'plan'], array_keys($chips));
        self::assertStringNotContainsString('status', $chips['statuses']['remove_url']);
        self::assertStringContainsString('q=SUB', $chips['statuses']['remove_url']);
        self::assertStringNotContainsString('q=', $chips['q']['remove_url']);
        self::assertStringNotContainsString('plan=', $chips['plan']['remove_url']);
        self::assertStringContainsString('plan=5', $chips['q']['remove_url']);
        self::assertSame('Gold box (Travis)', $chips['plan']['value'], 'The chip names the plan.');
    }
}

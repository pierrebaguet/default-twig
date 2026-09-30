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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Customer;

use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerCart;
use BackOfficeDefaultTwigBundle\Repository\CustomerCartRepository;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerCartsProvider;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The carts section of the customer sheet: the cart being filled, the abandoned ones, and
 * nothing the database no longer holds or that became an order.
 */
final class CustomerCartsProviderTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private CustomerCartsProvider $provider;

    private \DateTimeImmutable $now;

    private Product $product;

    private bool $horizonWritten = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/'.$route.'/'.($parameters['product_id'] ?? ''));
        $this->provider = new CustomerCartsProvider(new CustomerCartRepository(), $urls);
        $this->now = new \DateTimeImmutable('now');
        $this->product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['title' => 'Blue shirt'],
        );
    }

    protected function tearDown(): void
    {
        // ConfigQuery keeps a static cache the transaction rollback does not reach.
        if ($this->horizonWritten) {
            ConfigQuery::write(CustomerCartsProvider::CART_NO_ORDER_DAYS_CONFIG_KEY, (string) CustomerCartsProvider::DEFAULT_CART_NO_ORDER_DAYS);
            ConfigQuery::resetCache();
        }

        parent::tearDown();
    }

    public function testTheCartBeingFilledComesWithItsLinesAndItsAmount(): void
    {
        $customer = $this->customer();
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product, null, ['quantity' => 2.0, 'price' => '12.500000']);
        $this->factory->cartItem($cart, $this->product, null, ['quantity' => 1.0, 'price' => '20.000000', 'promo' => 1, 'promoPrice' => '15.000000']);

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertNotNull($carts->current);
        self::assertSame((int) $cart->getId(), $carts->current->id);
        self::assertSame(2, $carts->current->lineCount);
        self::assertEqualsWithDelta(40.0, $carts->current->amount, 0.001, 'Two at 12.50, and one at its promo price of 15.');
        self::assertCount(2, $carts->currentLines);
        self::assertSame('Blue shirt', $carts->currentLines[0]->title);
        self::assertSame('/admin.products.update/'.$this->product->getId(), $carts->currentLines[0]->productUrl);
        self::assertTrue($carts->currentLines[1]->isPromo);
        self::assertEqualsWithDelta(15.0, $carts->currentLines[1]->lineTotal, 0.001);
        self::assertSame([], $carts->abandoned);
    }

    /**
     * A line a promotion offered is free: the core leaves it out of the cart total
     * (BaseFacade::getCartTotalPrice()), and so does the sheet. The line stays listed.
     */
    public function testAnOfferedLineIsNotAddedToTheAmountOfTheCart(): void
    {
        $customer = $this->customer();
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product, null, ['quantity' => 1.0, 'price' => '10.000000']);
        $this->factory->cartItem($cart, $this->product, null, ['quantity' => 1.0, 'price' => '50.000000', 'isOffered' => 1]);
        $old = $this->factory->cart($customer);
        $this->factory->cartItem($old, $this->product, null, ['quantity' => 1.0, 'price' => '20.000000']);
        $this->factory->cartItem($old, $this->product, null, ['quantity' => 2.0, 'price' => '30.000000', 'isOffered' => 1]);
        $this->age($old, '-5 days');

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertNotNull($carts->current);
        self::assertEqualsWithDelta(10.0, $carts->current->amount, 0.001, 'The offered line is free: the cart is worth 10, not 60.');
        self::assertCount(2, $carts->currentLines, 'The offered line is still listed.');
        self::assertTrue($carts->currentLines[1]->isOffered);
        self::assertSame(0.0, $carts->currentLines[1]->lineTotal);
        self::assertCount(1, $carts->abandoned);
        self::assertEqualsWithDelta(20.0, $carts->abandoned[0]->amount, 0.001, 'An abandoned cart leaves its offered lines out too.');
    }

    public function testACartLeftThreeWeeksAgoIsAbandonedAndDated(): void
    {
        $customer = $this->customer();
        $cart = $this->filledCart($customer);
        $this->age($cart, '-21 days');

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertNull($carts->current);
        self::assertCount(1, $carts->abandoned);
        self::assertSame((int) $cart->getId(), $carts->abandoned[0]->id);
        self::assertSame($this->now->modify('-21 days')->format('Y-m-d'), $carts->abandoned[0]->lastActivityAt->format('Y-m-d'));
        self::assertSame([], $carts->currentLines, 'The lines of an abandoned cart are not loaded.');
    }

    public function testACartThatBecameAnOrderIsNotListed(): void
    {
        $customer = $this->customer();
        $order = $this->factory->order($customer);
        // order.cart_id has no foreign key, hence no Propel relation to follow.
        $cart = CartQuery::create()->findPk($order->getCartId());
        self::assertInstanceOf(Cart::class, $cart);
        $this->factory->cartItem($cart, $this->product);

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertNull($carts->current);
        self::assertSame([], $carts->abandoned);
    }

    public function testAnEmptyCartIsNotListed(): void
    {
        $customer = $this->customer();
        $this->factory->cart($customer);

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertNull($carts->current);
        self::assertSame([], $carts->abandoned);
    }

    public function testACartPastThePurgeHorizonIsNotLookedFor(): void
    {
        $customer = $this->customer();
        $cart = $this->filledCart($customer);
        $this->age($cart, '-90 days');

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertSame([], $carts->abandoned, 'The purge deletes it after 60 days by default.');
        self::assertSame(60, $carts->horizonDays);

        $this->horizonWritten = true;
        ConfigQuery::write(CustomerCartsProvider::CART_NO_ORDER_DAYS_CONFIG_KEY, '120');

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertCount(1, $carts->abandoned, 'A shop that keeps its carts longer sees them longer.');
        self::assertSame(120, $carts->horizonDays);
    }

    public function testALineAddedTodayKeepsAnOldCartCurrent(): void
    {
        $customer = $this->customer();
        $cart = $this->filledCart($customer);
        $this->age($cart, '-10 days');
        $this->factory->cartItem($cart, $this->product);

        $carts = $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);

        self::assertInstanceOf(CustomerCart::class, $carts->current, 'Adding a line does not touch the cart row: the line dates the activity.');
        self::assertSame((int) $cart->getId(), $carts->current->id);
    }

    public function testTheSectionCostsAFixedNumberOfQueries(): void
    {
        $customer = $this->customer();
        $current = $this->filledCart($customer);
        for ($i = 0; $i < 5; ++$i) {
            $this->factory->cartItem($current, $this->product);
        }
        for ($i = 0; $i < 8; ++$i) {
            $this->age($this->filledCart($customer), '-'.(2 + $i).' days');
        }

        $queries = QueryCounter::count(function () use ($customer): void {
            $this->provider->compute((int) $customer->getId(), 'en_US', $this->now);
        });

        self::assertLessThanOrEqual(5, $queries, 'The carts, the lines of the current one, the currencies: not one query per cart or per line.');
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function filledCart(Customer $customer): Cart
    {
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product);

        return $cart;
    }

    /**
     * Written in SQL: saving the models would stamp updated_at with the current time.
     */
    private function age(Cart $cart, string $modifier): void
    {
        $date = $this->now->modify($modifier)->format('Y-m-d H:i:s');
        $connection = $this->getPropelConnection();

        $connection->prepare('UPDATE cart SET created_at = ?, updated_at = ? WHERE id = ?')->execute([$date, $date, $cart->getId()]);
        $connection->prepare('UPDATE cart_item SET created_at = ?, updated_at = ? WHERE cart_id = ?')->execute([$date, $date, $cart->getId()]);
    }
}

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

namespace BackOfficeDefaultTwigBundle\Tests\Service;

use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerChoiceProvider;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Customer;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * What the shared customer picker writes in its option labels.
 *
 * The picker is used by the coupon conditions as well as by the audience of a
 * reserved sale, and the coupon screen only asks for the coupon right. An e-mail
 * address is personal data the customer resource guards, so it belongs in the label
 * only for an admin who is allowed to view customers - otherwise the coupon screen
 * hands out the mailing list of the shop to whoever may edit a coupon.
 */
final class CustomerChoiceProviderTest extends IntegrationTestCase
{
    public function testTheEmailIsInTheLabelOfAnAdminWhoMayViewCustomers(): void
    {
        $customer = $this->customer();

        $label = $this->labelOf($customer, granted: true);

        self::assertStringContainsString((string) $customer->getEmail(), $label);
        self::assertStringContainsString((string) $customer->getLastname(), $label);
        self::assertStringContainsString((string) $customer->getRef(), $label);
    }

    public function testTheEmailIsLeftOutWithoutTheRightToViewCustomers(): void
    {
        $customer = $this->customer();

        $label = $this->labelOf($customer, granted: false);

        self::assertStringNotContainsString((string) $customer->getEmail(), $label);
        self::assertStringNotContainsString('@', $label, 'no part of an address may leak into the option text');
        self::assertStringContainsString((string) $customer->getLastname(), $label, 'the picker still has to name who it lists');
        self::assertStringContainsString((string) $customer->getRef(), $label);
    }

    public function testTheScreensAreToldWhetherTheLabelsCarryAnEmail(): void
    {
        self::assertTrue($this->provider(granted: true)->labelsIncludeEmail());
        self::assertFalse($this->provider(granted: false)->labelsIncludeEmail());
    }

    private function labelOf(Customer $customer, bool $granted): string
    {
        $id = (int) $customer->getId();

        foreach ($this->provider($granted)->choices([$id]) as $choice) {
            if ($choice['id'] === $id) {
                return $choice['label'];
            }
        }

        self::fail('an already-selected customer must stay in the list, or saving would drop them');
    }

    private function provider(bool $granted): CustomerChoiceProvider
    {
        $access = $this->createMock(AdminAccessChecker::class);
        $access->method('canView')->with(AdminResources::CUSTOMER)->willReturn($granted);

        return new CustomerChoiceProvider(new CustomerRepository(new OrderRepository()), $access);
    }

    private function customer(): Customer
    {
        $title = CustomerTitleQuery::create()->findOne($this->getPropelConnection());
        self::assertNotNull($title, 'the seeded shop has customer titles');

        return $this->createFixtureFactory()->customer($title, [
            'lastname' => 'Choicelabel',
            'email' => 'choice-label-'.uniqid().'@example.com',
        ]);
    }
}

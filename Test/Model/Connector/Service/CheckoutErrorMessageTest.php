<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Connector\Service;

use Comfino\ComfinoGateway\Model\Connector\Service\CheckoutErrorMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutErrorMessageTest extends TestCase
{
    private const GENERIC = 'ComfinoPay could not accept the order data. Please check your billing and delivery details and try again.';

    /**
     * @return array<string, array{0: array<int|string, mixed>, 1: string}>
     */
    public static function fieldErrorsProvider(): array
    {
        return [
            'dotted path' => [['customer.address.postalCode' => ['Invalid code 00-00']], 'Postal code is required.'],
            'bracket path' => [['customer[phoneNumber]' => 'bad'], 'Phone number is required. Please add a phone number to your billing or delivery address.'],
            'plain field' => [['email' => 'jan@example.com is invalid'], 'Invalid customer e-mail address. Please check your account contact data.'],
            'two fields' => [['customer.firstName' => 'x', 'customer.lastName' => 'y'], 'First name is required. Last name is required.'],
            'same message once' => [['customer.address.street' => 'x', 'customer.address.buildingNumber' => 'y'], 'Delivery address is required.'],
            'unknown field' => [['cart' => 'too big'], self::GENERIC],
            'no field named' => [['' => ['Something failed']], self::GENERIC],
            'bare list' => [['Internal error at api host'], self::GENERIC],
            'nothing' => [[], self::GENERIC],
        ];
    }

    /**
     * @param array<int|string, mixed> $fieldErrors
     */
    #[DataProvider('fieldErrorsProvider')]
    public function testShopperMessage(array $fieldErrors, string $expected): void
    {
        $this->assertSame($expected, CheckoutErrorMessage::forFieldErrors($fieldErrors));
    }

    public function testNeverRepeatsTheApiText(): void
    {
        $message = CheckoutErrorMessage::forFieldErrors(['customer.email' => 'jan.kowalski@example.com rejected']);

        $this->assertStringNotContainsString('jan.kowalski@example.com', $message);
    }
}

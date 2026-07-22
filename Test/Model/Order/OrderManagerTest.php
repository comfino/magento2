<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Order;

use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\CartBuilderInterface;
use Comfino\Shop\Order\CustomerBuilderInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order as MagentoOrder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OrderManagerTest extends TestCase
{
    private CartBuilderInterface&MockObject $cartBuilder;
    private CustomerBuilderInterface&MockObject $customerBuilder;
    private OrderManager $orderManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartBuilder = $this->createMock(CartBuilderInterface::class);
        $this->customerBuilder = $this->createMock(CustomerBuilderInterface::class);
        $this->orderManager = new OrderManager($this->cartBuilder, $this->customerBuilder);
    }

    public function testGetShopCartDelegatesToCartBuilderWithPriceModifier(): void
    {
        $quote = $this->createMock(Quote::class);
        $cart = $this->createMock(Cart::class);

        $this->cartBuilder
            ->expects($this->once())
            ->method('buildCart')
            ->with($quote, 250)
            ->willReturn($cart);

        $this->assertSame($cart, $this->orderManager->getShopCart($quote, 250));
    }

    public function testGetShopCartDefaultsPriceModifierToZero(): void
    {
        $quote = $this->createMock(Quote::class);
        $cart = $this->createMock(Cart::class);

        $this->cartBuilder
            ->expects($this->once())
            ->method('buildCart')
            ->with($quote, 0)
            ->willReturn($cart);

        $this->assertSame($cart, $this->orderManager->getShopCart($quote));
    }

    public function testGetShopCartFromProductDelegatesToBuilder(): void
    {
        $product = $this->createMock(Product::class);
        $cart = $this->createMock(Cart::class);

        $this->cartBuilder
            ->expects($this->once())
            ->method('buildCartFromSingleProduct')
            ->with($product)
            ->willReturn($cart);

        $this->assertSame($cart, $this->orderManager->getShopCartFromProduct($product));
    }

    public function testGetShopCartFromOrderDelegatesToBuilder(): void
    {
        $order = $this->createMock(MagentoOrder::class);
        $cart = $this->createMock(Cart::class);

        $this->cartBuilder
            ->expects($this->once())
            ->method('buildCart')
            ->with($order)
            ->willReturn($cart);

        $this->assertSame($cart, $this->orderManager->getShopCartFromOrder($order));
    }

    public function testGetShopCustomerFromOrderMarksRegisteredCustomerAsRegular(): void
    {
        $order = $this->createMock(MagentoOrder::class);
        $order->method('getCustomerId')->willReturn(42);
        $customer = $this->createMock(Customer::class);

        $this->customerBuilder
            ->expects($this->once())
            ->method('buildCustomer')
            ->with($order, '203.0.113.7', true, true)
            ->willReturn($customer);

        $this->assertSame(
            $customer,
            $this->orderManager->getShopCustomerFromOrder($order, '203.0.113.7', true)
        );
    }

    public function testGetShopCustomerFromOrderMarksGuestAsNotRegular(): void
    {
        $order = $this->createMock(MagentoOrder::class);
        $order->method('getCustomerId')->willReturn(null);
        $customer = $this->createMock(Customer::class);

        $this->customerBuilder
            ->expects($this->once())
            ->method('buildCustomer')
            ->with($order, '198.51.100.1', false, false)
            ->willReturn($customer);

        $this->assertSame(
            $customer,
            $this->orderManager->getShopCustomerFromOrder($order, '198.51.100.1', false)
        );
    }
}
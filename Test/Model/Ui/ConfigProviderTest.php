<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Ui
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Ui;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\ComfinoGateway\Model\Ui\ConfigProvider;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Order\Cart\ProductInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CODE constant and the paywall cart serialization delegated to the SDK PaywallCartSerializer.
 * getConfig() is excluded: its static facades need Magento's ObjectManager, unavailable in a unit test.
 */
final class ConfigProviderTest extends TestCase
{
    public function testCodeConstant(): void
    {
        $this->assertSame('comfino', ConfigProvider::CODE);
    }

    /**
     * No checkout config (no key, token or cart) for a store view where the payment method is off.
     */
    public function testReturnsNoConfigWhileThePaymentMethodIsInactive(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->with(ConfigProvider::XML_PATH_ACTIVE)->willReturn(false);

        $checkoutSession = $this->createMock(Session::class);
        $checkoutSession->expects($this->never())->method('getQuote');

        $provider = new ConfigProvider(
            $this->createMock(Data::class),
            $checkoutSession,
            $this->createMock(OrderManager::class),
            $this->createMock(AbstractShopEnvironmentBuilder::class),
            $scopeConfig
        );

        $this->assertSame([], $provider->getConfig());
    }

    public function testReturnsNullForEmptyCart(): void
    {
        $cart = $this->createMock(Cart::class);
        $cart->method('getCartItems')->willReturn([]);

        $this->assertNull(PaywallCartSerializer::serialize($cart));
    }

    public function testSerializesSingleProductWithFullData(): void
    {
        $product = $this->makeProduct(
            name: 'Sofa',
            price: 250000,
            netPrice: 203252,
            taxRate: 23,
            taxValue: 46748,
            category: 'Furniture'
        );

        $cart = $this->makeCart(
            totalValue: 250000,
            deliveryCost: 1500,
            deliveryNetCost: 1220,
            deliveryTaxRate: 23,
            deliveryTaxValue: 280,
            items: [new CartItem($product, 2)]
        );

        $result = PaywallCartSerializer::serialize($cart);

        $this->assertSame([
            'totalAmount' => 250000,
            'deliveryCost' => 1500,
            'deliveryNetCost' => 1220,
            'deliveryCostVatRate' => 23,
            'deliveryCostVatAmount' => 280,
            'products' => [
                [
                    'name' => 'Sofa',
                    'quantity' => 2,
                    'price' => 250000,
                    'netPrice' => 203252,
                    'vatRate' => 23,
                    'vatAmount' => 46748,
                    'category' => 'Furniture',
                ],
            ],
        ], $result);
    }

    public function testNullableProductFieldsFallBackToDefaults(): void
    {
        $product = $this->makeProduct(
            name: 'Lamp',
            price: 9900,
            netPrice: null,
            taxRate: null,
            taxValue: null,
            category: null
        );

        $cart = $this->makeCart(
            totalValue: 9900,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            items: [new CartItem($product, 1)]
        );

        $result = PaywallCartSerializer::serialize($cart);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['deliveryNetCost']);
        $this->assertSame(0, $result['deliveryCostVatRate']);
        $this->assertSame(0, $result['deliveryCostVatAmount']);

        $serializedProduct = $result['products'][0];
        $this->assertSame($product->getPrice(), $serializedProduct['netPrice']);
        $this->assertSame(0, $serializedProduct['vatRate']);
        $this->assertSame(0, $serializedProduct['vatAmount']);
        $this->assertSame('', $serializedProduct['category']);
    }

    public function testSerializesMultipleProducts(): void
    {
        $cart = $this->makeCart(
            totalValue: 30000,
            deliveryCost: 0,
            deliveryNetCost: 0,
            deliveryTaxRate: 0,
            deliveryTaxValue: 0,
            items: [
                new CartItem($this->makeProduct('A', 10000, 10000, 0, 0, 'Cat'), 1),
                new CartItem($this->makeProduct('B', 20000, 20000, 0, 0, 'Cat'), 3),
            ]
        );

        $result = PaywallCartSerializer::serialize($cart);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['products']);
        $this->assertSame('A', $result['products'][0]['name']);
        $this->assertSame('B', $result['products'][1]['name']);
        $this->assertSame(3, $result['products'][1]['quantity']);
    }

    private function makeProduct(
        string $name,
        int $price,
        ?int $netPrice,
        ?int $taxRate,
        ?int $taxValue,
        ?string $category
    ): ProductInterface {
        return new Product(
            name: $name,
            price: $price,
            id: null,
            category: $category,
            ean: null,
            photoUrl: null,
            categoryIds: null,
            netPrice: $netPrice,
            taxRate: $taxRate,
            taxValue: $taxValue
        );
    }

    /**
     * @param CartItem[] $items
     */
    private function makeCart(
        int $totalValue,
        int $deliveryCost,
        ?int $deliveryNetCost,
        ?int $deliveryTaxRate,
        ?int $deliveryTaxValue,
        array $items
    ): Cart {
        return new Cart(
            totalValue: $totalValue,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: $deliveryCost,
            deliveryNetCost: $deliveryNetCost,
            deliveryTaxRate: $deliveryTaxRate,
            deliveryTaxValue: $deliveryTaxValue,
            cartItems: $items
        );
    }
}

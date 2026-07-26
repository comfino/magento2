<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Ui
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Ui;

use Comfino\ComfinoGateway\Model\Ui\ConfigProvider;
use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Order\Cart\ProductInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CODE constant plus the paywall cart serialization the provider now delegates to the SDK
 * PaywallCartSerializer (boundary regression guard for the shape the paywall iframe consumes). The getConfig() flow is
 * excluded: it depends on a chain of static facades (SettingsManager, PaywallConfigBuilder, ConfigManager category
 * tree) that resolve services through Magento's ObjectManager, which is unavailable in a pure unit test.
 */
final class ConfigProviderTest extends TestCase
{
    public function testCodeConstant(): void
    {
        $this->assertSame('comfino', ConfigProvider::CODE);
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
            netPrice: $netPrice,
            taxRate: $taxRate,
            taxValue: $taxValue,
            categoryIds: null
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
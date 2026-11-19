<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Block\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Block\Payment;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Block\Payment\Comfino;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use JsonException;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * The Comfino payment block feeds the Alpine.js paywall template. Blocks are built via an anonymous subclass with a
 * no-op constructor and collaborators injected by reflection, as in InitTest / SystemInfoTest.
 *
 * getConfig() and the JSON getters that read it need Magento's ObjectManager, so they are covered end-to-end elsewhere;
 * here only the ObjectManager-free surface is tested.
 */
final class ComfinoTest extends TestCase
{
    private CheckoutSession&MockObject $checkoutSession;
    private Data&MockObject $helper;
    private OrderManager&MockObject $orderManager;
    private AbstractShopEnvironmentBuilder&MockObject $shopEnvironmentBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->helper = $this->createMock(Data::class);
        $this->orderManager = $this->createMock(OrderManager::class);
        $this->shopEnvironmentBuilder = $this->createMock(AbstractShopEnvironmentBuilder::class);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetShopLanguageDelegatesToHelper(): void
    {
        $this->helper->method('getShopLanguage')->willReturn('pl');

        $this->assertSame('pl', $this->makeBlock()->getShopLanguage());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyReturnsQuoteCurrency(): void
    {
        $this->checkoutSession->method('getQuote')->willReturn($this->quoteWithCurrency('EUR'));

        $this->assertSame('EUR', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyFallsBackToPlnWhenCurrencyMissing(): void
    {
        $this->checkoutSession->method('getQuote')->willReturn($this->quoteWithCurrency(null));

        $this->assertSame('PLN', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyFallsBackToPlnWhenQuoteUnavailable(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('PLN', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetShopEnvironmentDelegatesToBuilderWithCheckoutContext(): void
    {
        $environment = ['platform' => 'magento', 'theme' => ['family' => 'luma']];

        $this->shopEnvironmentBuilder->expects($this->once())
            ->method('buildForFrontend')
            ->with(['type' => 'checkout'])
            ->willReturn($environment);

        $this->assertSame($environment, $this->makeBlock()->getShopEnvironment());
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function testGetShopEnvironmentJsonSerializesEnvironment(): void
    {
        $environment = ['platform' => 'magento', 'theme' => ['family' => 'luma']];

        $this->shopEnvironmentBuilder->method('buildForFrontend')->willReturn($environment);

        $this->assertSame($environment, json_decode($this->makeBlock()->getShopEnvironmentJson(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCartSerializesShopCart(): void
    {
        $quote = $this->createMock(Quote::class);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->orderManager->method('getShopCart')->with($quote)->willReturn($this->makeShopCart());

        $cart = $this->makeBlock()->getCart();

        $this->assertNotNull($cart);
        $this->assertSame(250000, $cart['totalAmount']);
        $this->assertCount(1, $cart['products']);
        $this->assertSame('Sofa', $cart['products'][0]['name']);
        $this->assertSame(2, $cart['products'][0]['quantity']);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCartReturnsNullWhenQuoteUnavailable(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertNull($this->makeBlock()->getCart());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCartJsonReturnsEmptyStringWhenCartUnavailable(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('', $this->makeBlock()->getCartJson());
    }

    /**
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testGetCartJsonSerializesCartToJson(): void
    {
        $quote = $this->createMock(Quote::class);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->orderManager->method('getShopCart')->with($quote)->willReturn($this->makeShopCart());

        $json = json_decode($this->makeBlock()->getCartJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($json);
        $this->assertSame(250000, $json['totalAmount']);
    }

    /**
     * The cart JSON is written into an inline <script>: a product name must not be able to end it or break out of a string.
     *
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testGetCartJsonCannotEndTheScript(): void
    {
        $quote = $this->createMock(Quote::class);
        $name = "Sofa</script><script>alert('x')</script> & \"co\"";

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->orderManager->method('getShopCart')->willReturn($this->makeShopCart($name));

        $json = $this->makeBlock()->getCartJson();

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringNotContainsString('&', $json);
        // The SDK cart serializer already strips tags from product names; quotes and ampersands survive, escaped.
        $this->assertSame("Sofaalert('x') & \"co\"", json_decode($json, true, 512, JSON_THROW_ON_ERROR)['products'][0]['name']);
    }

    /**
     * The cart is built once per block although the paywall config, the allowed types and the cart JSON all use it.
     *
     * @throws ReflectionException
     */
    public function testBuildsTheShopCartOnce(): void
    {
        $this->checkoutSession->method('getQuote')->willReturn($this->createMock(Quote::class));
        $this->orderManager->expects($this->once())->method('getShopCart')->willReturn($this->makeShopCart());

        $block = $this->makeBlock();
        $block->getCart();
        $block->getCartJson();
        $block->getCart();
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductTypesReturnsNullWhenQuoteUnavailable(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertNull($this->makeBlock()->getAllowedProductTypes());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductTypesJsonReturnsEmptyStringWhenNull(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('', $this->makeBlock()->getAllowedProductTypesJson());
    }

    public function testConstructorInjectsCollaborators(): void
    {
        /* Smoke-checks that the promoted / assigned properties exist with the expected names, so the
           reflection injection in makeBlock() stays valid if the constructor is refactored. */
        $class = new ReflectionClass(Comfino::class);

        foreach (['checkoutSession', 'helper', 'orderManager', 'shopEnvironmentBuilder', 'serializer'] as $name) {
            $this->assertTrue($class->hasProperty($name), "Comfino block must declare property $name");
        }
    }

    /**
     * @throws ReflectionException
     */
    private function makeBlock(): Comfino
    {
        $block = new class extends Comfino {
            public function __construct()
            {
            }
        };

        foreach (
            [
                'checkoutSession' => $this->checkoutSession,
                'helper' => $this->helper,
                'orderManager' => $this->orderManager,
                'shopEnvironmentBuilder' => $this->shopEnvironmentBuilder,
                'serializer' => new JsonSerializer(),
            ] as $name => $value
        ) {
            (new ReflectionProperty(Comfino::class, $name))->setValue($block, $value);
        }

        return $block;
    }

    /**
     * Builds a Quote mock whose currency resolves to the given code, or null to simulate a missing currency object.
     */
    private function quoteWithCurrency(?string $currencyCode): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);

        if ($currencyCode === null) {
            $quote->method('getCurrency')->willReturn(null);

            return $quote;
        }

        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn($currencyCode);
        $quote->method('getCurrency')->willReturn($currency);

        return $quote;
    }

    private function makeShopCart(string $productName = 'Sofa'): Cart
    {
        $product = new Product(
            name: $productName,
            price: 250000,
            id: null,
            category: 'Furniture',
            ean: null,
            photoUrl: null,
            categoryIds: null,
            netPrice: 203252,
            taxRate: 23,
            taxValue: 46748
        );

        return new Cart(
            totalValue: 250000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 1500,
            deliveryNetCost: 1220,
            deliveryTaxRate: 23,
            deliveryTaxValue: 280,
            cartItems: [new CartItem($product, 2)]
        );
    }
}

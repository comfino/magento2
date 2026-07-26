<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Block\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
 * The Comfino payment block feeds the Alpine.js paywall template. It extends Template (a heavy constructor), so each
 * block is built via an anonymous subclass with a no-op constructor and its collaborators injected by reflection —
 * the same pattern as InitTest / SystemInfoTest.
 *
 * getConfig() (and the JSON getters that read it) pull the paywall config through a chain of static facades
 * (ConfigManager, SettingsManager) that resolve services via Magento's ObjectManager, which is unavailable in a pure
 * unit test — that flow is exercised end-to-end elsewhere. Here we cover the ObjectManager-free surface: language /
 * currency resolution, the shop-environment delegation, the cart serialization delegation, and the
 * quote-unavailable guard branches.
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

        $this->assertSame(
            $environment,
            json_decode($this->makeBlock()->getShopEnvironmentJson(), true, 512, JSON_THROW_ON_ERROR)
        );
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

    private function makeShopCart(): Cart
    {
        $product = new Product(
            name: 'Sofa',
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

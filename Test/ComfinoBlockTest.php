<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\ComfinoGateway\Block\Payment\Comfino;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\PaywallConfig;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * Tests for Block/Payment/Comfino public methods that do not require a full Magento bootstrap.
 *
 * The block extends Template whose constructor pulls in ~15 Context services, so we bypass it via
 * newInstanceWithoutConstructor() and inject only the properties each test exercises.
 */
final class ComfinoBlockTest extends TestCase
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

    protected function tearDown(): void
    {
        ConfigurationManager::reset();
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);

        parent::tearDown();
    }

    /**
     * Creates a Comfino block instance without invoking the Magento Template constructor chain,
     * injecting only the properties needed for the tests below.
     *
     * @throws ReflectionException
     */
    private function makeBlock(): Comfino
    {
        $block = (new ReflectionClass(Comfino::class))->newInstanceWithoutConstructor();

        foreach (
            [
                'checkoutSession' => $this->checkoutSession,
                'helper' => $this->helper,
                'orderManager' => $this->orderManager,
                'shopEnvironmentBuilder' => $this->shopEnvironmentBuilder,
                'serializer' => new JsonSerializer(),
            ] as $propertyName => $value
        ) {
            $property = new ReflectionProperty(Comfino::class, $propertyName);
            $property->setValue($block, $value);
        }

        return $block;
    }

    // --- getStoreCurrency() ---

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyReturnsCurrencyCodeFromQuote(): void
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn('EUR');

        $quote = $this->createMock(Quote::class);
        $quote->method('getCurrency')->willReturn($currency);

        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->assertSame('EUR', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyFallsBackToPlnWhenCurrencyCodeIsNull(): void
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn(null);

        $quote = $this->createMock(Quote::class);
        $quote->method('getCurrency')->willReturn($currency);

        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->assertSame('PLN', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyFallsBackToPlnOnNoSuchEntityException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('PLN', $this->makeBlock()->getStoreCurrency());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStoreCurrencyFallsBackToPlnOnLocalizedException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new LocalizedException(__('error')));

        $this->assertSame('PLN', $this->makeBlock()->getStoreCurrency());
    }

    // --- getShopLanguage() ---

    /**
     * @throws ReflectionException
     */
    public function testGetShopLanguageDelegatesToHelper(): void
    {
        $this->helper->method('getShopLanguage')->willReturn('pl');

        $this->assertSame('pl', $this->makeBlock()->getShopLanguage());
    }

    // --- getCartJson() ---

    /**
     * @throws ReflectionException
     */
    public function testGetCartJsonReturnsEmptyStringOnNoSuchEntityException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('', $this->makeBlock()->getCartJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCartJsonReturnsEmptyStringOnLocalizedException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new LocalizedException(__('error')));

        $this->assertSame('', $this->makeBlock()->getCartJson());
    }

    // --- getAllowedProductTypesJson() ---

    /**
     * Pins current behavior: when getQuote() throws, the block falls open — returns '' from getAllowedProductTypesJson
     * (because the private getAllowedProductTypeStrings() returns null → JSON '' → template sees "no restriction" and
     * renders the paywall). Failing the cart build does NOT hide the paywall. If the product / security policy ever
     * changes to fail-closed, these tests must be updated alongside the block.
     *
     * @throws ReflectionException
     */
    public function testGetAllowedProductTypesJsonReturnsEmptyOnNoSuchEntityException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new NoSuchEntityException());

        $this->assertSame('', $this->makeBlock()->getAllowedProductTypesJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductTypesJsonReturnsEmptyOnLocalizedException(): void
    {
        $this->checkoutSession->method('getQuote')->willThrowException(new LocalizedException(__('error')));

        $this->assertSame('', $this->makeBlock()->getAllowedProductTypesJson());
    }

    // --- getAllowedProductsConfigJson() ---

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductsConfigJsonReturnsEmptyWhenConfigIsNull(): void
    {
        $block = $this->makeBlock();
        $this->injectPaywallConfig($block, allowedProductsConfig: null);

        $this->assertSame('', $block->getAllowedProductsConfigJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductsConfigJsonReturnsEmptyWhenConfigIsEmptyArray(): void
    {
        $block = $this->makeBlock();
        $this->injectPaywallConfig($block, allowedProductsConfig: []);

        $this->assertSame('', $block->getAllowedProductsConfigJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAllowedProductsConfigJsonReturnsSerializedConfigWhenPopulated(): void
    {
        $config = [
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 3, 'maxTerm' => 12],
            ['type' => 'PAY_LATER', 'terms' => [30]],
        ];
        $block = $this->makeBlock();

        $this->injectPaywallConfig($block, allowedProductsConfig: $config);

        $this->assertSame($config, json_decode($block->getAllowedProductsConfigJson(), true));
    }

    // --- getCreditorsJson() ---

    /**
     * @throws ReflectionException
     */
    public function testGetCreditorsJsonReturnsEmptyWhenCreditorsIsNull(): void
    {
        $block = $this->makeBlock();

        $this->injectPaywallConfig($block);

        $this->assertSame('', $block->getCreditorsJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCreditorsJsonReturnsEmptyWhenCreditorsIsEmptyArray(): void
    {
        $block = $this->makeBlock();

        $this->injectPaywallConfig($block, creditors: []);

        $this->assertSame('', $block->getCreditorsJson());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetCreditorsJsonReturnsSerializedCreditorsWhenPopulated(): void
    {
        $creditors = ['INSTALLMENTS_ZERO_PERCENT' => ['CRED_A', 'CRED_B']];
        $block = $this->makeBlock();

        $this->injectPaywallConfig($block, creditors: $creditors);

        $this->assertSame($creditors, json_decode($block->getCreditorsJson(), true));
    }

    // --- getShopEnvironmentJson() ---

    /**
     * @throws ReflectionException
     */
    public function testGetShopEnvironmentJsonDelegatesToBuilderWithCheckoutContext(): void
    {
        $env = ['platform' => 'magento2', 'pageContext' => ['type' => 'checkout']];
        $this->shopEnvironmentBuilder
            ->expects($this->once())
            ->method('buildForFrontend')
            ->with(['type' => 'checkout'])
            ->willReturn($env);

        $this->assertSame($env, json_decode($this->makeBlock()->getShopEnvironmentJson(), true));
    }

    /**
     * Injects a pre-built PaywallConfig into the block's cached property, so getConfig()-dependent methods
     * (getAllowedProductsConfigJson, getCreditorsJson) can be exercised without bootstrapping the SDK.
     *
     * @param array<int, array<string, mixed>>|null $allowedProductsConfig
     * @param array<string, string[]>|null $creditors
     */
    private function injectPaywallConfig(
        Comfino $block,
        ?array $allowedProductsConfig = null,
        ?array $creditors = null
    ): void {
        $config = new PaywallConfig(
            authToken: 'test-token',
            loanAmount: 0,
            environment: 'sandbox',
            sdkScriptUrl: 'https://example.test/sdk.js',
            allowedProductTypes: null,
            directRedirect: false,
            paywallSettings: null,
            creditors: $creditors,
            allowedProductsConfig: $allowedProductsConfig
        );

        (new ReflectionProperty(Comfino::class, 'paywallConfig'))->setValue($block, $config);
    }
}

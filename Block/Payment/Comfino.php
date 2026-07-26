<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block\Payment;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\Frontend\PaywallConfigBuilder;
use Comfino\Frontend\PaywallConfig;
use Comfino\Frontend\PaywallSettings;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Backend\Payment\ProductTypeTools;
use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Enum\ProductListType;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Payment block for the Comfino paywall.
 *
 * Rendered by comfino.phtml (Alpine.js) on the checkout payment step. Provides all data the template needs to
 * bootstrap the paywall iframe: PaywallConfig (API/widget keys, loan amount, SDK URL, allowed product types),
 * cart JSON for COMFINO_CART_UPDATE, shop language, currency, and shop environment.
 *
 * Allowed product types are resolved against the active category/value filters via SettingsManager; null means
 * no restriction, an empty array means all types are filtered out and the paywall should be hidden.
 */
class Comfino extends Template
{
    private CheckoutSession $checkoutSession;
    private Data $helper;
    private ?PaywallConfig $paywallConfig = null;
    private readonly JsonSerializer $serializer;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        Data $helper,
        private readonly OrderManager $orderManager,
        private readonly AbstractShopEnvironmentBuilder $shopEnvironmentBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->checkoutSession = $checkoutSession;
        $this->helper = $helper;
        $this->serializer = new JsonSerializer();
    }

    /**
     * Builds and caches the paywall configuration object.
     */
    public function getConfig(): PaywallConfig
    {
        if ($this->paywallConfig === null) {
            $this->paywallConfig = PaywallConfigBuilder::buildConfig(
                apiKey: ConfigManager::getApiKey() ?? '',
                accessToken: ConfigManager::getErrorLoggingAccessToken(),
                widgetKey: ConfigManager::getWidgetKey() ?? '',
                loanAmount: $this->getLoanAmount(),
                sandboxMode: ConfigManager::isSandboxMode(),
                sdkScriptUrl: ConfigManager::getSdkScriptUrl(),
                allowedProductTypes: $this->getAllowedProductTypes(),
                directRedirect: (bool) ConfigManager::getConfigurationValue('COMFINO_PAYWALL_DIRECT_REDIRECT'),
                paywallSettings: new PaywallSettings(
                    language: $this->helper->getShopLanguage(),
                    currency: $this->getStoreCurrency(),
                    customPaywallCss: ConfigManager::getConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL', '') ?: null
                ),
                creditors: SettingsManager::getCreditors() ?: null,
                allowedProductsConfig: SettingsManager::getAllowedProductsConfigForFrontend()
            );
        }

        return $this->paywallConfig;
    }

    /**
     * Returns the cart grand total in grosze (1 PLN = 100 grosze).
     */
    private function getLoanAmount(): int
    {
        try {
            return (int) round($this->checkoutSession->getQuote()->getGrandTotal() * 100);
        } catch (NoSuchEntityException | LocalizedException) {
            return 0;
        }
    }

    /**
     * Returns allowed product type API strings for use in the template, or null when no filters are configured.
     * Returns an empty array when all types are filtered out — paywall should be hidden.
     *
     * @return string[]|null
     */
    public function getAllowedProductTypes(): ?array
    {
        try {
            $shopCart = $this->orderManager->getShopCart($this->checkoutSession->getQuote());
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        $allowedProductTypes = SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $shopCart);

        if ($allowedProductTypes === null) {
            return null;
        }

        return ProductTypeTools::toApiValues($allowedProductTypes);
    }

    /**
     * Returns JSON-encoded allowed product types for use in the template.
     */
    public function getAllowedProductTypesJson(): string
    {
        if (($allowedProductTypes = $this->getAllowedProductTypes()) === null) {
            return '';
        }

        return $this->serializer->serialize($allowedProductTypes);
    }

    /**
     * Returns JSON-encoded creditors map for use in the template, or empty string when none configured.
     */
    public function getCreditorsJson(): string
    {
        $creditors = $this->getConfig()->creditors;

        if ($creditors === null || $creditors === []) {
            return '';
        }

        return $this->serializer->serialize($creditors);
    }

    /**
     * Returns product-type display names (code => name in shop language) for use in the template.
     * Forwarded to the SDK as PaywallOptions.productTypeNames.
     *
     * @return array<string, string>
     */
    public function getProductTypeNames(): array
    {
        return SettingsManager::getProductTypes(ProductListType::PAYWALL->value);
    }

    /**
     * Returns JSON-encoded product-type display names for use in the template, or empty string when none available.
     */
    public function getProductTypeNamesJson(): string
    {
        $productTypeNames = $this->getProductTypeNames();

        if ($productTypeNames === []) {
            return '';
        }

        return $this->serializer->serialize($productTypeNames);
    }

    /**
     * Returns JSON-encoded per-product-type term limits for use in the template, or empty string when none configured.
     */
    public function getAllowedProductsConfigJson(): string
    {
        $allowedProductsConfig = $this->getConfig()->allowedProductsConfig;

        if ($allowedProductsConfig === null || $allowedProductsConfig === []) {
            return '';
        }

        return $this->serializer->serialize($allowedProductsConfig);
    }

    /**
     * Returns a cart data array for the paywall iframe (COMFINO_CART_UPDATE), or null when not available.
     *
     * @return array<string, mixed>|null
     */
    public function getCart(): ?array
    {
        try {
            $shopCart = $this->orderManager->getShopCart($this->checkoutSession->getQuote());
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        return PaywallCartSerializer::serialize($shopCart);
    }

    /**
     * Returns JSON-encoded cart data for the paywall iframe (COMFINO_CART_UPDATE).
     * Returns empty string when the cart cannot be built (no items).
     */
    public function getCartJson(): string
    {
        if (($cart = $this->getCart()) === null) {
            return '';
        }

        return $this->serializer->serialize($cart);
    }

    /**
     * Returns the store language code (e.g. "pl", "en") for the paywall init options.
     */
    public function getShopLanguage(): string
    {
        return $this->helper->getShopLanguage();
    }

    /**
     * Returns the active quote currency code, falling back to PLN if the session is unavailable.
     */
    public function getStoreCurrency(): string
    {
        try {
            return $this->checkoutSession->getQuote()->getCurrency()?->getQuoteCurrencyCode() ?? 'PLN';
        } catch (NoSuchEntityException | LocalizedException) {
            return 'PLN';
        }
    }

    /**
     * Returns a structured shop environment for the paywall iframe.
     *
     * @return array<string, mixed>
     */
    public function getShopEnvironment(): array
    {
        return $this->shopEnvironmentBuilder->buildForFrontend(['type' => 'checkout']);
    }

    /**
     * Returns JSON-encoded shop environment forwarded to the paywall iframe.
     *
     * Built via the SDK's MagentoShopEnvironmentBuilder, the same as the widget, so the paywall and widget receive a
     * consistent view of the shop (platform / theme / locale / page-context). Page context is set to 'checkout' here.
     */
    public function getShopEnvironmentJson(): string
    {
        return $this->serializer->serialize($this->getShopEnvironment());
    }

    /**
     * SHA3-256 HMAC forwarded to the SDK as `paymentMethodItem.auth`. Hyvä themes read this directly from the block
     * (no checkoutConfig pipeline). Must never be stamped into a DOM attribute.
     */
    public function getPaymentMethodAuth(): string
    {
        return ConfigManager::getPaywallLogoAuthHash();
    }
}

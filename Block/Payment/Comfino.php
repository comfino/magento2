<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block\Payment;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
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
use Comfino\Shop\Cart;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Payment block for the ComfinoPay paywall: provides the data comfino.phtml needs to bootstrap it.
 *
 * Allowed product types come from SettingsManager: null means no restriction, an empty array means the paywall is hidden.
 */
class Comfino extends Template
{
    /* Flags keeping values such as "</script>" in a product name from breaking out of the inline script. */
    public const SCRIPT_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    private CheckoutSession $checkoutSession;
    private Data $helper;
    private ?PaywallConfig $paywallConfig = null;
    private ?Cart $shopCart = null;
    private bool $shopCartResolved = false;
    /** @var string[]|null|false False until resolved */
    private array|null|false $allowedProductTypes = false;
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
            // Same track ID handling as the Luma ConfigProvider: pin it for this checkout and pass it to the template.
            ApiClient::pinCheckoutTrackId();

            $checkoutFlags = SettingsManager::getCheckoutFlags();

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
                    customPaywallCss: ConfigManager::getCustomCssUrl('COMFINO_PAYWALL_CUSTOM_CSS_URL')
                ),
                creditors: SettingsManager::getCreditors() ?: null,
                allowedProductsConfig: SettingsManager::getAllowedProductsConfigForFrontend(),
                trackId: ApiClient::getInstance()->getTrackId(),
                flags: $checkoutFlags['flags'],
                flagAttributes: $checkoutFlags['flagAttributes']
            );
        }

        return $this->paywallConfig;
    }

    /**
     * Returns true when the merchant allowed the frontend SDK to collect paywall activity statistics (opt-in).
     */
    public function isTrackingConsentGranted(): bool
    {
        return (bool) ConfigManager::getConfigurationValue('COMFINO_PAYWALL_TRACKING_CONSENT');
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
     * Allowed product type API strings, or null when no filters are configured; an empty array hides the paywall.
     *
     * @return string[]|null
     */
    public function getAllowedProductTypes(): ?array
    {
        if ($this->allowedProductTypes !== false) {
            return $this->allowedProductTypes;
        }

        if (($shopCart = $this->getShopCart()) === null) {
            return $this->allowedProductTypes = null;
        }

        $allowedProductTypes = SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $shopCart);

        return $this->allowedProductTypes = $allowedProductTypes !== null ? ProductTypeTools::toApiValues($allowedProductTypes) : null;
    }

    /**
     * Returns JSON-encoded allowed product types for use in the template.
     */
    public function getAllowedProductTypesJson(): string
    {
        if (($allowedProductTypes = $this->getAllowedProductTypes()) === null) {
            return '';
        }

        return $this->serializer->serialize($allowedProductTypes, self::SCRIPT_JSON_FLAGS);
    }

    /**
     * Promotion hint data as HTML-escaped JSON for a `application/json` script element; '' when there are no flags.
     * Never print it into a JavaScript literal: the flags map is not filtered.
     */
    public function getRemoteFlagsJson(): string
    {
        $config = $this->getConfig();

        return RemoteFlags::encode(['flags' => $config->flags, 'flagAttributes' => $config->flagAttributes]);
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

        return $this->serializer->serialize($creditors, self::SCRIPT_JSON_FLAGS);
    }

    /**
     * Product-type display names (code => name in shop language), forwarded as PaywallOptions.productTypeNames.
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

        return $this->serializer->serialize($productTypeNames, self::SCRIPT_JSON_FLAGS);
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

        return $this->serializer->serialize($allowedProductsConfig, self::SCRIPT_JSON_FLAGS);
    }

    /**
     * Cart data for the paywall iframe, or null when not available.
     *
     * @return array<string, mixed>|null
     */
    public function getCart(): ?array
    {
        return ($shopCart = $this->getShopCart()) !== null ? PaywallCartSerializer::serialize($shopCart) : null;
    }

    /**
     * JSON-encoded cart for the paywall iframe; empty string when the cart cannot be built.
     */
    public function getCartJson(): string
    {
        if (($cart = $this->getCart()) === null) {
            return '';
        }

        return $this->serializer->serialize($cart, self::SCRIPT_JSON_FLAGS);
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
     * Structured shop environment for the paywall iframe.
     *
     * @return array<string, mixed>
     */
    public function getShopEnvironment(): array
    {
        return $this->shopEnvironmentBuilder->buildForFrontend(['type' => 'checkout']);
    }

    /**
     * JSON-encoded shop environment, built like the widget's so both see the same shop; page context is 'checkout'.
     */
    public function getShopEnvironmentJson(): string
    {
        return $this->serializer->serialize($this->getShopEnvironment(), self::SCRIPT_JSON_FLAGS);
    }

    /**
     * Logo request authentication value for `paymentMethodItem.auth`; Hyvä reads it from the block. Never stamp it into a DOM attribute.
     */
    public function getPaymentMethodAuth(): string
    {
        return ConfigManager::getPaywallLogoAuthHash();
    }

    /**
     * The checkout quote as an SDK cart, built once per block.
     */
    private function getShopCart(): ?Cart
    {
        if (!$this->shopCartResolved) {
            $this->shopCartResolved = true;

            try {
                $this->shopCart = $this->orderManager->getShopCart($this->checkoutSession->getQuote());
            } catch (NoSuchEntityException | LocalizedException) {
                $this->shopCart = null;
            }
        }

        return $this->shopCart;
    }
}

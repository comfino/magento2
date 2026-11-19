<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Ui
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Ui;

use Comfino\Backend\Payment\ProductTypeTools;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Logger\Debug;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Enum\ProductListType;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Frontend\PaywallConfigBuilder;
use Comfino\Frontend\PaywallSettings;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'comfino';
    public const XML_PATH_ACTIVE = 'payment/comfino/active';

    public function __construct(
        protected Data $helper,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderManager $orderManager,
        private readonly AbstractShopEnvironmentBuilder $shopEnvironmentBuilder,
        private readonly ?ScopeConfigInterface $scopeConfig = null
    ) {
    }

    /**
     * Checkout configuration passed via window.checkoutConfig to the JS renderer (comfino-method.js).
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        /* Checkout config is rendered into every checkout page: nothing is built (no API key, token or cart) for a store
           view where the payment method is switched off. */
        if ($this->scopeConfig !== null && !$this->scopeConfig->isSetFlag(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE)) {
            return [];
        }

        ApiClient::pinCheckoutTrackId();

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            Debug::logEvent(
                'Failed to retrieve quote for ComfinoPay payment method configuration.',
                ['exceptionMessage' => $e->getMessage()],
                'CONFIG_PROVIDER'
            );

            return [];
        }

        $loanAmount = (int) round($quote->getGrandTotal() * 100);

        // Allowed product types: null = no filters, [] = everything filtered out (hide paywall), [...] = subset.
        $allowedProductTypes = null;

        $cart = null;

        try {
            $shopCart = $this->orderManager->getShopCart($quote);
            $productTypes = SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $shopCart);

            if ($productTypes !== null) {
                $allowedProductTypes = ProductTypeTools::toApiValues($productTypes);
            }

            $cart = PaywallCartSerializer::serialize($shopCart);

            Debug::logEvent('Serialized paywall cart.', ['cart' => $cart, 'itemCount' => count($shopCart->getCartItems())], 'CONFIG_PROVIDER');
        } catch (LocalizedException $e) {
            // Cart build failed - proceed without product type restriction.
            Debug::logEvent(
                'Failed to build cart for paywall.',
                ['exceptionMessage' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                'CONFIG_PROVIDER'
            );
        }

        $currency = $quote->getCurrency()?->getQuoteCurrencyCode() ?? 'PLN';

        $paywallConfig = PaywallConfigBuilder::buildConfig(
            apiKey: ConfigManager::getApiKey() ?? '',
            accessToken: ConfigManager::getErrorLoggingAccessToken(),
            widgetKey: ConfigManager::getWidgetKey() ?? '',
            loanAmount: $loanAmount,
            sandboxMode: ConfigManager::isSandboxMode(),
            sdkScriptUrl: ConfigManager::getSdkScriptUrl(),
            allowedProductTypes: $allowedProductTypes,
            directRedirect: (bool) ConfigManager::getConfigurationValue('COMFINO_PAYWALL_DIRECT_REDIRECT'),
            paywallSettings: new PaywallSettings(
                language: $this->helper->getShopLanguage(),
                currency: $currency,
                customPaywallCss: ConfigManager::getCustomCssUrl('COMFINO_PAYWALL_CUSTOM_CSS_URL')
            ),
            creditors: SettingsManager::getCreditors() ?: null,
            allowedProductsConfig: SettingsManager::getAllowedProductsConfigForFrontend(),
            trackId: ApiClient::getInstance()->getTrackId()
        );

        $trackingConsent = (bool) ConfigManager::getConfigurationValue('COMFINO_PAYWALL_TRACKING_CONSENT');

        return [
            'payment' => [
                self::CODE => array_merge($paywallConfig->getAsArray(), [
                    /* Set here rather than via PaywallConfigBuilder::buildConfig(trackingConsent: ...), because the released
                       comfino/php-sdk does not have that parameter yet. Always a boolean, so the key is present in every checkout config. */
                    'trackingConsent' => $trackingConsent,
                    'isActive' => true,
                    'pluginVersion' => $this->helper->getModuleVersion(),
                    'cart' => $cart,
                    'shopEnvironment' => $this->shopEnvironmentBuilder->buildForFrontend(['type' => 'checkout']),
                    'productTypeNames' => SettingsManager::getProductTypes(ProductListType::PAYWALL->value) ?: null,
                    'paymentMethodAuth' => ConfigManager::getPaywallLogoAuthHash(),
                    'paymentMethodLabel' => ConfigManager::getConfigurationValue('COMFINO_PAYMENT_TEXT') ?: null,
                    'defaultLogoUrl' => ConfigManager::getDefaultLogoUrl(),
                ]),
            ],
        ];
    }
}

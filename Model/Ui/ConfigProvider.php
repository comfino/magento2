<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Ui
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Ui;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Logger\Debug;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Backend\Payment\ProductTypeTools;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Enum\ProductListType;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Frontend\PaywallConfigBuilder;
use Comfino\Frontend\PaywallSettings;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'comfino';

    public function __construct(
        protected Data $helper,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderManager $orderManager,
        private readonly AbstractShopEnvironmentBuilder $shopEnvironmentBuilder
    ) {
    }

    /**
     * Returns checkout configuration for Comfino payment method. Auth token, loan amount, SDK URL, environment, and
     * allowed product types are passed via window.checkoutConfig to the JS renderer (comfino-method.js). The SDK
     * constructs the full paywall URL from authToken + loanAmount + environment.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        ApiClient::pinCheckoutTrackId();

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            Debug::logEvent(
                'Failed to retrieve quote for Comfino payment method configuration.',
                ['exceptionMessage' => $e->getMessage()],
                'CONFIG_PROVIDER'
            );

            return [];
        }

        $loanAmount = (int) round($quote->getGrandTotal() * 100);

        // Compute allowed product types based on active category/value filters.
        // null = no filters active (no restriction)
        // [] = all product types filtered out (paywall should be hidden)
        // [...] = filtered subset of product types
        $allowedProductTypes = null;

        $cart = null;

        try {
            $shopCart = $this->orderManager->getShopCart($quote);
            $productTypes = SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $shopCart);

            if ($productTypes !== null) {
                $allowedProductTypes = ProductTypeTools::toApiValues($productTypes);
            }

            $cart = PaywallCartSerializer::serialize($shopCart);

            Debug::logEvent(
                'Serialized paywall cart.',
                ['cart' => $cart, 'itemCount' => count($shopCart->getCartItems())],
                'CONFIG_PROVIDER'
            );
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
                customPaywallCss: ConfigManager::getConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL', '') ?: null
            ),
            creditors: SettingsManager::getCreditors() ?: null,
            allowedProductsConfig: SettingsManager::getAllowedProductsConfigForFrontend(),
            trackId: ApiClient::getInstance()->getTrackId()
        );

        return [
            'payment' => [
                self::CODE => array_merge($paywallConfig->getAsArray(), [
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

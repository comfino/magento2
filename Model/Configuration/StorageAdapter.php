<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Helper\Data;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Bridges Magento ScopeConfig and the shared library ConfigurationManager.
 *
 * @see StorageAdapterInterface
 * @see ConfigurationManager
 */
class StorageAdapter implements StorageAdapterInterface
{
    /** API keys are stored encrypted (masked admin fields); load() decrypts and save() encrypts them. */
    private const API_KEY_OPTIONS = ['COMFINO_API_KEY', 'COMFINO_SANDBOX_API_KEY'];

    /** @var int[] */
    private array $optTypeFlags;

    /** @var array<string, string> Maps COMFINO_* key names -> Magento XML config paths */
    private static array $keyToXmlPath = [
        // Payment settings
        'COMFINO_API_KEY' => Data::XML_PATH_API_KEY,
        'COMFINO_PAYMENT_TEXT' => Data::XML_PATH_PAYMENT_TEXT,
        'COMFINO_MINIMAL_CART_AMOUNT' => Data::XML_PATH_MINIMAL_CART_AMOUNT,
        'COMFINO_USE_ORDER_REFERENCE' => Data::XML_PATH_USE_ORDER_REFERENCE,
        'COMFINO_PAYWALL_DIRECT_REDIRECT' => Data::XML_PATH_PAYWALL_DIRECT_REDIRECT,
        'COMFINO_PAYWALL_CUSTOM_CSS_URL' => Data::XML_PATH_PAYWALL_CUSTOM_CSS_URL,
        'COMFINO_PAYWALL_TRACKING_CONSENT' => Data::XML_PATH_PAYWALL_TRACKING_CONSENT,
        // Sale settings
        'COMFINO_ALLOWED_PRODUCTS_CONFIG' => Data::XML_PATH_ALLOWED_PRODUCTS_CONFIG,
        'COMFINO_PRODUCT_CATEGORY_FILTERS' => Data::XML_PATH_PRODUCT_CATEGORY_FILTERS,
        // Widget settings
        'COMFINO_WIDGET_ENABLED' => Data::XML_PATH_WIDGET_ENABLED,
        'COMFINO_WIDGET_KEY' => Data::XML_PATH_WIDGET_KEY,
        'COMFINO_WIDGET_PRICE_SELECTOR' => Data::XML_PATH_WIDGET_PRICE_SELECTOR,
        'COMFINO_WIDGET_PRICE_ATTRIBUTE' => Data::XML_PATH_WIDGET_PRICE_ATTRIBUTE,
        'COMFINO_WIDGET_TARGET_SELECTOR' => Data::XML_PATH_WIDGET_TARGET_SELECTOR,
        'COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR' => Data::XML_PATH_WIDGET_PRICE_OBSERVER_SELECTOR,
        'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL' => Data::XML_PATH_WIDGET_PRICE_OBSERVER_LEVEL,
        'COMFINO_WIDGET_TYPE' => Data::XML_PATH_WIDGET_TYPE,
        'COMFINO_WIDGET_OFFER_TYPES' => Data::XML_PATH_WIDGET_OFFER_TYPE,
        'COMFINO_WIDGET_EMBED_METHOD' => Data::XML_PATH_WIDGET_EMBED_METHOD,
        'COMFINO_WIDGET_SHOW_PROVIDER_LOGOS' => Data::XML_PATH_WIDGET_SHOW_PROVIDER_LOGOS,
        'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL' => Data::XML_PATH_WIDGET_CUSTOM_BANNER_CSS_URL,
        'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL' => Data::XML_PATH_WIDGET_CUSTOM_CALCULATOR_CSS_URL,
        // Developer settings
        'COMFINO_IS_SANDBOX' => Data::XML_PATH_SANDBOX_ENABLED,
        'COMFINO_SANDBOX_API_KEY' => Data::XML_PATH_SANDBOX_API_KEY,
        'COMFINO_DEBUG' => Data::XML_PATH_DEBUG,
        'COMFINO_SERVICE_MODE' => Data::XML_PATH_SERVICE_MODE,
        'COMFINO_DEV_ENV_VARS' => Data::XML_PATH_DEV_ENV_VARS,
        'COMFINO_WEBHOOK_IP_ALLOWLIST_ENFORCE' => Data::XML_PATH_WEBHOOK_IP_ALLOWLIST_ENFORCE,
        'COMFINO_DEBUG_LOG_REMOTE_READ' => Data::XML_PATH_DEBUG_LOG_REMOTE_READ,
        // Hidden settings
        'COMFINO_SDK_SCRIPT_VERSION' => Data::XML_PATH_SDK_SCRIPT_VERSION,
        'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => Data::XML_PATH_CAT_FILTER_AVAIL_PROD_TYPES,
        'COMFINO_IGNORED_STATUSES' => Data::XML_PATH_IGNORED_STATUSES,
        'COMFINO_FORBIDDEN_STATUSES' => Data::XML_PATH_FORBIDDEN_STATUSES,
        'COMFINO_STATUS_MAP' => Data::XML_PATH_STATUS_MAP,
        'COMFINO_API_CONNECT_TIMEOUT' => Data::XML_PATH_API_CONNECT_TIMEOUT,
        'COMFINO_API_TIMEOUT' => Data::XML_PATH_API_TIMEOUT,
        'COMFINO_API_CONNECT_NUM_ATTEMPTS' => Data::XML_PATH_API_CONNECT_NUM_ATTEMPTS,
        'COMFINO_PROD_CAT_CACHE_TTL' => Data::XML_PATH_PROD_CAT_CACHE_TTL,
        'COMFINO_INITIAL_ORDER_STATUS' => Data::XML_PATH_INITIAL_ORDER_STATUS,
        'COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => Data::XML_PATH_ALLOWED_PRODUCTS_CONFIG_ENABLED,
        'COMFINO_REMOTE_FLAGS' => Data::XML_PATH_REMOTE_FLAGS,
        'COMFINO_ERROR_LOGGING_ACCESS_TOKEN' => Data::XML_PATH_ERROR_LOGGING_ACCESS_TOKEN,
        'COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT' => Data::XML_PATH_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT,
        // Outbound request queue settings
        'COMFINO_RETRY_QUEUE_ENABLED' => Data::XML_PATH_RETRY_QUEUE_ENABLED,
        'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS' => Data::XML_PATH_RETRY_QUEUE_MAX_ATTEMPTS,
        'COMFINO_RETRY_QUEUE_BATCH_SIZE' => Data::XML_PATH_RETRY_QUEUE_BATCH_SIZE,
        'COMFINO_RETRY_QUEUE_COOLDOWN' => Data::XML_PATH_RETRY_QUEUE_COOLDOWN,
        'COMFINO_API_QUEUE_CONNECT_TIMEOUT' => Data::XML_PATH_API_QUEUE_CONNECT_TIMEOUT,
        'COMFINO_API_QUEUE_TIMEOUT' => Data::XML_PATH_API_QUEUE_TIMEOUT,
    ];

    /**
     * @param int|null $storeId Store to scope load()/save() to. When null, load() uses the ambient current store (fragile in
     *                          cron, CLI and webhook processing) and save() writes to the default scope.
     * @param string $scope Scope type for load() and save() when $storeId is set; ignored otherwise.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ?int $storeId = null,
        private readonly string $scope = ScopeInterface::SCOPE_STORE,
        private readonly ?EncryptorInterface $encryptor = null
    ) {
        $this->optTypeFlags = array_merge(array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)));
    }

    /**
     * Loads all configuration values from Magento store config.
     */
    public function load(): array
    {
        $configuration = [];
        $defaults = ConfigManager::getDefaultConfigurationValues();

        foreach ($this->optTypeFlags as $optName => $optTypeFlags) {
            $xmlPath = self::$keyToXmlPath[$optName] ?? null;

            if ($xmlPath !== null) {
                $value = $this->storeId !== null
                    ? $this->scopeConfig->getValue($xmlPath, $this->scope, $this->storeId)
                    : $this->scopeConfig->getValue($xmlPath, ScopeInterface::SCOPE_STORE);
                $configuration[$optName] = $value ?? ($defaults[$optName] ?? null);

                if (in_array($optName, self::API_KEY_OPTIONS, true)) {
                    $configuration[$optName] = ApiKeyCrypt::decrypt($configuration[$optName], $this->encryptor);
                }
            } else {
                $configuration[$optName] = $defaults[$optName] ?? null;
            }

            if ($optTypeFlags & ConfigurationManager::OPT_VALUE_TYPE_BOOL) {
                $configuration[$optName] = (bool) $configuration[$optName];
            }
        }

        return $configuration;
    }

    /**
     * Saves configuration values to Magento store config.
     */
    public function save($configurationOptions): void
    {
        $saved = false;

        foreach ($configurationOptions as $optName => $optValue) {
            $xmlPath = self::$keyToXmlPath[$optName] ?? null;

            if ($xmlPath !== null) {
                if (in_array($optName, self::API_KEY_OPTIONS, true)) {
                    $optValue = ApiKeyCrypt::encrypt($optValue, $this->encryptor);
                }

                if ($this->storeId !== null) {
                    $this->configWriter->save($xmlPath, $optValue, $this->scope, $this->storeId);
                } else {
                    $this->configWriter->save($xmlPath, $optValue);
                }

                $saved = true;
            }
        }

        if ($saved) {
            $this->cacheTypeList->cleanType('config');
        }
    }
}

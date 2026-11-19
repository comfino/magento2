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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Auth\FrontendLogAuthKeyGenerator;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;
use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\CategoryTree\BuildStrategy;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\CssUrlConstraint;
use Comfino\ComfinoGateway\Model\Configuration\Constraint\ListConstraint;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\Enum\OrderStatus;
use Comfino\Enum\OrderStatusInterface;
use Comfino\Enum\ProductListType;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\FrontendHelper;
use Comfino\Frontend\SdkUrlBuilder;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\StatusManager;
use Comfino\Shop\Product\CategoryTree;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use LogicException;
use Throwable;

/**
 * Facade over Comfino\Backend\Configuration\ConfigurationManager for Magento.
 *
 * @see ConfigurationManager
 */
class ConfigManager
{
    /* Luma's `.product-info-main` wraps the whole product view in Hyva and drops the banner to the page bottom, so
       `#product_addtocart_form` replaces it there. Only known Luma defaults are swapped, never a custom selector. */
    private const WIDGET_TARGET_SELECTOR_HYVA_DEFAULT = '#product_addtocart_form';
    private const WIDGET_TARGET_SELECTOR_LUMA_DEFAULTS = ['div.product-info-main', 'div.product-add-form'];

    /* Retry gate for a failed error-logging access token claim. @see refreshErrorLoggingTokenIfNeeded() */
    private const ERROR_LOGGING_TOKEN_COOLDOWN_KEY = 'comfino_error_logging_token_claim_cooldown';
    private const ERROR_LOGGING_TOKEN_COOLDOWN = 900;

    public const CONFIG_OPTIONS = [
        'payment_settings' => [
            'COMFINO_API_KEY' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_PAYMENT_TEXT' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_MINIMAL_CART_AMOUNT' => ConfigurationManager::OPT_VALUE_TYPE_FLOAT,
            'COMFINO_USE_ORDER_REFERENCE' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_PAYWALL_DIRECT_REDIRECT' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_PAYWALL_TRACKING_CONSENT' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
        ],
        'sale_settings' => [
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => ConfigurationManager::OPT_VALUE_TYPE_JSON,
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => ConfigurationManager::OPT_VALUE_TYPE_JSON,
        ],
        'widget_settings' => [
            'COMFINO_WIDGET_ENABLED' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_WIDGET_KEY' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_PRICE_SELECTOR' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_PRICE_ATTRIBUTE' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_TARGET_SELECTOR' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_WIDGET_TYPE' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_OFFER_TYPES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            'COMFINO_WIDGET_EMBED_METHOD' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_SHOW_PROVIDER_LOGOS' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
        ],
        'developer_settings' => [
            'COMFINO_IS_SANDBOX' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_SANDBOX_API_KEY' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_DEBUG' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_SERVICE_MODE' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_DEV_ENV_VARS' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_WEBHOOK_IP_ALLOWLIST_ENFORCE' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_DEBUG_LOG_REMOTE_READ' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
        ],
        'hidden_settings' => [
            'COMFINO_SDK_SCRIPT_VERSION' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            'COMFINO_IGNORED_STATUSES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            'COMFINO_FORBIDDEN_STATUSES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            'COMFINO_STATUS_MAP' => ConfigurationManager::OPT_VALUE_TYPE_JSON,
            'COMFINO_API_CONNECT_TIMEOUT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_API_TIMEOUT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_API_CONNECT_NUM_ATTEMPTS' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_PROD_CAT_CACHE_TTL' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_INITIAL_ORDER_STATUS' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_REMOTE_FLAGS' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            // Outbound request queue settings
            'COMFINO_RETRY_QUEUE_ENABLED' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_RETRY_QUEUE_BATCH_SIZE' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_RETRY_QUEUE_COOLDOWN' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_API_QUEUE_CONNECT_TIMEOUT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            'COMFINO_API_QUEUE_TIMEOUT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
            // Error logging access credentials
            'COMFINO_ERROR_LOGGING_ACCESS_TOKEN' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT' => ConfigurationManager::OPT_VALUE_TYPE_INT,
        ],
    ];

    public const ACCESSIBLE_CONFIG_OPTIONS = [
        // Payment settings
        'COMFINO_PAYMENT_TEXT',
        'COMFINO_MINIMAL_CART_AMOUNT',
        'COMFINO_USE_ORDER_REFERENCE',
        'COMFINO_PAYWALL_DIRECT_REDIRECT',
        'COMFINO_PAYWALL_CUSTOM_CSS_URL',
        // Sale settings
        'COMFINO_ALLOWED_PRODUCTS_CONFIG',
        'COMFINO_PRODUCT_CATEGORY_FILTERS',
        // Widget settings
        'COMFINO_WIDGET_ENABLED',
        'COMFINO_WIDGET_KEY',
        'COMFINO_WIDGET_PRICE_SELECTOR',
        'COMFINO_WIDGET_PRICE_ATTRIBUTE',
        'COMFINO_WIDGET_TARGET_SELECTOR',
        'COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR',
        'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL',
        'COMFINO_WIDGET_TYPE',
        'COMFINO_WIDGET_OFFER_TYPES',
        'COMFINO_WIDGET_EMBED_METHOD',
        'COMFINO_WIDGET_SHOW_PROVIDER_LOGOS',
        'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL',
        'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL',
        // Developer settings
        'COMFINO_IS_SANDBOX',
        'COMFINO_DEBUG',
        'COMFINO_SERVICE_MODE',
        'COMFINO_DEV_ENV_VARS',
        // Hidden settings
        'COMFINO_SDK_SCRIPT_VERSION',
        'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES',
        'COMFINO_IGNORED_STATUSES',
        'COMFINO_FORBIDDEN_STATUSES',
        'COMFINO_STATUS_MAP',
        'COMFINO_API_CONNECT_TIMEOUT',
        'COMFINO_API_TIMEOUT',
        'COMFINO_API_CONNECT_NUM_ATTEMPTS',
        'COMFINO_PROD_CAT_CACHE_TTL',
        'COMFINO_INITIAL_ORDER_STATUS',
        'COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED',
        // Promotion hint: the flag names ComfinoPay pushes after adding or removing a flag
        'COMFINO_REMOTE_FLAGS',
    ];

    private static ?ConfigurationManager $configurationManager = null;
    /** @var array<int, ConfigurationManager> Store-scoped managers, keyed by store ID. */
    private static array $storeScopedManagers = [];
    /** @var array<string, ConfigurationManager> Default/website-scoped managers, keyed by "<scope>:<scope ID>". */
    private static array $scopedManagers = [];
    /** @var array<string, int>|null */
    private static ?array $availConfigOptions = null;
    private static ?CollectionFactory $collectionFactory = null;
    /** @var string[]|null */
    private static ?array $shopHosts = null;
    /** @var array<string, ConfigValueConstraintInterface>|null */
    private static ?array $valueConstraints = null;

    public static function getInstance(): ConfigurationManager
    {
        if (self::$configurationManager === null) {
            self::$configurationManager = ConfigurationManager::getInstance(
                self::getAvailableConfigOptions(),
                self::ACCESSIBLE_CONFIG_OPTIONS,
                ConfigurationManager::OPT_SERIALIZE_ARRAYS,
                new StorageAdapter(
                    ObjectManager::getInstance()->get(ScopeConfigInterface::class),
                    ObjectManager::getInstance()->get(WriterInterface::class),
                    ObjectManager::getInstance()->get(TypeListInterface::class),
                    encryptor: ObjectManager::getInstance()->get(EncryptorInterface::class)
                ),
                new JsonSerializer()
            );

            self::$configurationManager->setDefaults(self::getDefaultConfigurationValues());
            self::$configurationManager->setValueConstraints(self::getValueConstraints());

            /* Remote configuration writes go through this instance and StorageAdapter::save() writes them to the default
               scope, which is the scope the audit record names. */
            try {
                self::$configurationManager->setChangeObserver(
                    ObjectManager::getInstance()->get(ConfigChangeAudit::class),
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                );
            } catch (Throwable) {
                // Without the object manager (e.g. in setup scripts) changes are not recorded.
            }
        }

        return self::$configurationManager;
    }

    /**
     * Configuration manager bound to one store, bypassing ambient current-store resolution (always the default store in
     * cron/CLI). Required wherever another store's settings are read outside its request, e.g. the queue drain. Keeps
     * its own in-memory value cache, isolated from other stores.
     */
    public static function getStoreScopedInstance(int $storeId): ConfigurationManager
    {
        if (!isset(self::$storeScopedManagers[$storeId])) {
            self::$storeScopedManagers[$storeId] = self::createScopedManager(
                ScopeInterface::SCOPE_STORE,
                $storeId,
                'store:' . $storeId
            );
        }

        return self::$storeScopedManagers[$storeId];
    }

    /**
     * Configuration manager bound to a config scope (default, website or store view), bypassing ambient store resolution;
     * generalizes {@see getStoreScopedInstance()} for the admin scope switcher.
     *
     * @param string $scope ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ScopeInterface::SCOPE_WEBSITES or ScopeInterface::SCOPE_STORES
     */
    public static function getScopedInstance(string $scope, int $scopeId): ConfigurationManager
    {
        if ($scope === ScopeInterface::SCOPE_STORES || $scope === ScopeInterface::SCOPE_STORE) {
            return self::getStoreScopedInstance($scopeId);
        }

        $key = $scope . ':' . $scopeId;

        if (!isset(self::$scopedManagers[$key])) {
            self::$scopedManagers[$key] = self::createScopedManager($scope, $scopeId, $key);
        }

        return self::$scopedManagers[$key];
    }

    private static function createScopedManager(string $scope, int $scopeId, string $instanceKey): ConfigurationManager
    {
        $manager = ConfigurationManager::getInstance(
            self::getAvailableConfigOptions(),
            self::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            new StorageAdapter(
                ObjectManager::getInstance()->get(ScopeConfigInterface::class),
                ObjectManager::getInstance()->get(WriterInterface::class),
                ObjectManager::getInstance()->get(TypeListInterface::class),
                $scopeId,
                $scope,
                ObjectManager::getInstance()->get(EncryptorInterface::class)
            ),
            new JsonSerializer(),
            $instanceKey
        );

        $manager->setValueConstraints(self::getValueConstraints());

        try {
            $manager->setDefaults(self::getDefaultConfigurationValues());
        } catch (LogicException $e) {
            /* Defaults are already registered on this scope's instance (it survived from an earlier call in this request).
               Nothing to do - the values we need are present either way. */
        }

        return $manager;
    }

    /**
     * Store-scoped counterpart of {@see isSandboxMode()}. See {@see getStoreScopedInstance()} for when to use it.
     */
    public static function isSandboxModeForStore(int $storeId): bool
    {
        return (bool) self::getStoreScopedInstance($storeId)->getOptionWithDefault('COMFINO_IS_SANDBOX');
    }

    /**
     * Store-scoped counterpart of {@see getApiKey()}. See {@see getStoreScopedInstance()} for when to use it.
     */
    public static function getApiKeyForStore(int $storeId): ?string
    {
        $manager = self::getStoreScopedInstance($storeId);

        return self::isSandboxModeForStore($storeId)
            ? $manager->getOptionWithDefault('COMFINO_SANDBOX_API_KEY')
            : $manager->getOptionWithDefault('COMFINO_API_KEY');
    }

    /**
     * Store-scoped counterpart of {@see getApiHost()}: the API host override of the given store, not of the ambient one.
     */
    public static function getApiHostForStore(int $storeId): ?string
    {
        return SdkUrlBuilder::getApiHostOverride(
            (bool) self::getStoreScopedInstance($storeId)->getOptionWithDefault('COMFINO_DEV_ENV_VARS')
        );
    }

    /**
     * @param array<string>|null $selectedEnvFields
     *
     * @return array<string, mixed>
     */
    public static function getEnvironmentInfo(?array $selectedEnvFields = null): array
    {
        /** @var Data $dataHelper */
        $dataHelper = ObjectManager::getInstance()->get(Data::class);

        if (($serverSoftware = getenv('SERVER_SOFTWARE')) === false) {
            $serverSoftware = 'n/a';
        }

        if (($serverName = getenv('SERVER_NAME')) === false) {
            $serverName = 'n/a';
        }

        $envFields = [
            'plugin_version' => $dataHelper->getModuleVersion(),
            'plugin_build_ts' => Data::BUILD_TS,
            'shop_version' => $dataHelper->getShopVersion(),
            'php_version' => PHP_VERSION,
            'server_software' => $serverSoftware,
            'server_name' => $serverName,
            'database_version' => $dataHelper->getDatabaseInfo(),
        ];

        if (empty($selectedEnvFields)) {
            return $envFields;
        }

        return array_intersect_key($envFields, array_flip($selectedEnvFields));
    }

    public static function getCategoriesTree(): CategoryTree
    {
        /** @var CategoryTree|null $categoriesTree */
        static $categoriesTree = null;

        if ($categoriesTree === null) {
            if (self::$collectionFactory === null) {
                self::$collectionFactory = ObjectManager::getInstance()->get(CollectionFactory::class);
            }

            $categoriesTree = new CategoryTree(new BuildStrategy(self::$collectionFactory));
        }

        return $categoriesTree;
    }

    public static function getConfigurationValue(string $optionName, mixed $defaultValue = null): mixed
    {
        return self::getInstance()->getOptionWithDefault($optionName) ?? $defaultValue;
    }

    public static function isSandboxMode(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_IS_SANDBOX');
    }

    public static function isWidgetEnabled(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_WIDGET_ENABLED');
    }

    public static function isDebugMode(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_DEBUG');
    }

    public static function isServiceMode(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_SERVICE_MODE');
    }

    public static function isUseOrderReference(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_USE_ORDER_REFERENCE');
    }

    public static function isRetryQueueEnabled(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_RETRY_QUEUE_ENABLED');
    }

    /**
     * Whether webhook requests from addresses other than the ComfinoPay servers are rejected (otherwise only logged).
     */
    public static function isWebhookIpAllowlistEnforced(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_WEBHOOK_IP_ALLOWLIST_ENFORCE');
    }

    /**
     * Names of the shop's remote flags as ComfinoPay last pushed them through the remote configuration endpoint; only
     * names of upper-case letters, digits and underscores (1 to 64 characters) are returned.
     *
     * @return string[]
     */
    public static function getRemoteFlags(): array
    {
        $flags = ListConstraint::toList(self::getConfigurationValue('COMFINO_REMOTE_FLAGS')) ?? [];

        return array_values(array_unique(array_filter(
            $flags,
            static fn (string $flag): bool => preg_match(RemoteValueConstraints::REMOTE_FLAG_PATTERN, $flag) === 1
        )));
    }

    /**
     * Whether ComfinoPay support may read the debug log through the signed remote configuration endpoint.
     */
    public static function isDebugLogRemoteReadEnabled(): bool
    {
        return (bool) self::getInstance()->getOptionWithDefault('COMFINO_DEBUG_LOG_REMOTE_READ');
    }

    public static function useDevEnvVars(): bool
    {
        return getenv('COMFINO_DEV_ENV') === 'TRUE' && self::getInstance()->getOptionWithDefault('COMFINO_DEV_ENV_VARS');
    }

    public static function getApiHost(): ?string
    {
        return SdkUrlBuilder::getApiHostOverride(self::devEnvVarsEnabled());
    }

    public static function getSdkScriptUrl(): string
    {
        return SdkUrlBuilder::getSdkScriptUrl(
            self::isSandboxMode(),
            self::devEnvVarsEnabled(),
            (int) self::getConfigurationValue('COMFINO_SDK_SCRIPT_VERSION', SdkUrlBuilder::DEFAULT_SDK_VERSION)
        );
    }

    /**
     * CDN URL of the product-page widget script, which reads the `#comfino-widget-config` JSON block.
     */
    public static function getProductWidgetScriptUrl(): string
    {
        return SdkUrlBuilder::getProductWidgetScriptUrl(
            self::isSandboxMode(),
            'magento',
            self::devEnvVarsEnabled(),
            (int) self::getConfigurationValue('COMFINO_SDK_SCRIPT_VERSION', SdkUrlBuilder::DEFAULT_SDK_VERSION)
        );
    }

    /**
     * CDN URL of the SDK-hosted ComfinoPay logo used as the initial payment-tile placeholder; the SDK swaps it for the auth-gated logo at
     * runtime. Honors the same COMFINO_DEV_ENV_VARS opt-in and COMFINO_DEV_SDK_CDN_BASE_URL override as the other SDK URLs.
     */
    public static function getDefaultLogoUrl(): string
    {
        return SdkUrlBuilder::getDefaultLogoUrl(self::isSandboxMode(), self::devEnvVarsEnabled());
    }

    /**
     * Assembles the product-page widget config (the `#comfino-widget-config` JSON block). Null values are dropped, so
     * omitted options fall through to the SDK defaults.
     *
     * @param Product|null $product The viewed product when the caller already loaded it (saves a repository load)
     * @param Cart|null $shopCart The product as an SDK cart when the caller already built it
     *
     * @return array<string, mixed>
     */
    public static function getWidgetConfig(?int $productId, ?Product $product = null, ?Cart $shopCart = null): array
    {
        $settings = self::getConfigurationValues(
            'widget_settings',
            [
                'COMFINO_WIDGET_KEY',
                'COMFINO_WIDGET_PRICE_SELECTOR',
                'COMFINO_WIDGET_PRICE_ATTRIBUTE',
                'COMFINO_WIDGET_TARGET_SELECTOR',
                'COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR',
                'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL',
                'COMFINO_WIDGET_TYPE',
                'COMFINO_WIDGET_OFFER_TYPES',
                'COMFINO_WIDGET_EMBED_METHOD',
                'COMFINO_WIDGET_SHOW_PROVIDER_LOGOS',
            ]
        );

        $variables = self::getWidgetVariables($productId, $product, $shopCart);

        // getWidgetVariables() emits the string literal 'null' for absent fields; normalize to real null.
        $notNull = static fn ($value) => $value === 'null' ? null : $value;

        // Magento reports the product price as a major-unit float; the SDK expects grosze (smallest unit).
        $priceValue = $notNull($variables['PRODUCT_PRICE'] ?? null);
        $price = $priceValue === null ? null : (int) round(((float) $priceValue) * 100);

        $offerTypes = $settings['COMFINO_WIDGET_OFFER_TYPES'] ?? null;

        $widgetTargetSelector = self::resolveWidgetTargetSelector(
            $settings['COMFINO_WIDGET_TARGET_SELECTOR'] ?? null,
            $variables['SHOP_ENVIRONMENT']['theme']['family'] ?? null
        );

        $config = [
            'sdkScriptUrl' => self::getSdkScriptUrl(),
            'environment' => self::isSandboxMode() ? 'sandbox' : 'production',
            'widgetKey' => $settings['COMFINO_WIDGET_KEY'] ?? null,
            'widgetTargetSelector' => $widgetTargetSelector,
            'priceSelector' => $settings['COMFINO_WIDGET_PRICE_SELECTOR'] ?? null,
            'priceAttribute' => ($settings['COMFINO_WIDGET_PRICE_ATTRIBUTE'] ?? '') ?: null,
            'priceObserverSelector' => ($settings['COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR'] ?? '') ?: null,
            'priceObserverLevel' => (int) ($settings['COMFINO_WIDGET_PRICE_OBSERVER_LEVEL'] ?? 0),
            'embedMethod' => $settings['COMFINO_WIDGET_EMBED_METHOD'] ?? null,
            'widgetType' => $settings['COMFINO_WIDGET_TYPE'] ?? null,
            'offerTypes' => is_array($offerTypes) && $offerTypes !== [] ? array_values($offerTypes) : null,
            'showProviderLogos' => (bool) ($settings['COMFINO_WIDGET_SHOW_PROVIDER_LOGOS'] ?? false),
            'hasPriceInput' => false,
            'bannerCssUrl' => self::getCustomCssUrl('COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL'),
            'calculatorCssUrl' => self::getCustomCssUrl('COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL'),
            'price' => $price,
            'productId' => $notNull($variables['PRODUCT_ID'] ?? null),
            'availableProductTypes' => $variables['AVAILABLE_PRODUCT_TYPES'] ?? null,
            'productCartDetails' => $notNull($variables['PRODUCT_CART_DETAILS'] ?? null),
            'language' => $variables['LANGUAGE'] ?? null,
            'currency' => $variables['CURRENCY'] ?? null,
            'shopEnvironment' => $variables['SHOP_ENVIRONMENT'] ?? null,
            'trackingConsent' => (bool) self::getConfigurationValue('COMFINO_PAYWALL_TRACKING_CONSENT'),
        ];

        return array_filter($config, static fn ($value) => $value !== null);
    }

    /**
     * Resolves the widget banner target selector for the active theme family: a known Luma default is replaced with
     * {@see WIDGET_TARGET_SELECTOR_HYVA_DEFAULT} on Hyva, a customized selector is left untouched.
     *
     * @param string|null $configured The stored COMFINO_WIDGET_TARGET_SELECTOR value
     * @param string|null $themeFamily Normalized theme family reported by the shop environment (e.g. 'hyva', 'luma')
     *
     * @return string|null The selector to hand the widget SDK, or null when none is configured
     */
    private static function resolveWidgetTargetSelector(?string $configured, ?string $themeFamily): ?string
    {
        $usesLumaDefault = $configured === null
            || $configured === ''
            || in_array($configured, self::WIDGET_TARGET_SELECTOR_LUMA_DEFAULTS, true);

        if ($themeFamily === 'hyva' && $usesLumaDefault) {
            return self::WIDGET_TARGET_SELECTOR_HYVA_DEFAULT;
        }

        return $configured;
    }

    public static function getLogoUrl(): string
    {
        /** @var Data $dataHelper */
        $dataHelper = ObjectManager::getInstance()->get(Data::class);

        return ApiClient::getInstance()->getApiBaseUrl() . '/v1/get-logo-url?auth='
            . FrontendHelper::getLogoAuthHash(
                'MG',
                $dataHelper->getShopVersion(),
                $dataHelper->getModuleVersion(),
                Data::BUILD_TS
            );
    }

    /**
     * Logo request auth value for `paymentMethodItem.auth`; must never reach the DOM as a `data-*` attribute. Returned
     * unencoded because the SDK percent-encodes it itself.
     */
    public static function getPaywallLogoAuthHash(): string
    {
        /** @var Data $dataHelper */
        $dataHelper = ObjectManager::getInstance()->get(Data::class);

        return FrontendHelper::getPaywallLogoAuthHashRaw(
            'MG',
            $dataHelper->getShopVersion(),
            $dataHelper->getModuleVersion(),
            self::getApiKey() ?? '',
            self::getWidgetKey() ?? '',
            Data::BUILD_TS
        );
    }

    public static function getApiKey(): ?string
    {
        return self::isSandboxMode()
            ? self::getConfigurationValue('COMFINO_SANDBOX_API_KEY')
            : self::getConfigurationValue('COMFINO_API_KEY');
    }

    public static function getWidgetKey(): ?string
    {
        return self::getConfigurationValue('COMFINO_WIDGET_KEY');
    }

    public static function getErrorLoggingAccessToken(): string
    {
        return (string) (self::getConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN') ?? '');
    }

    public static function getErrorLoggingAccessTokenExpiresAt(): int
    {
        return (int) (self::getConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT') ?? 0);
    }

    /**
     * Claims a fresh error-logging access token when the stored one is missing or about to expire. A failed claim sets a cooldown
     * marker in the shared cache; without it every request would retry and an unreachable API would add seconds of latency to each.
     */
    public static function refreshErrorLoggingTokenIfNeeded(): void
    {
        if (empty(self::getApiKey())) {
            return;
        }

        if (self::getErrorLoggingAccessToken() !== '' && self::getErrorLoggingAccessTokenExpiresAt() > time() + 3600) {
            return;
        }

        if (self::tokenClaimOnCooldown()) {
            return;
        }

        try {
            $response = ApiClient::getInstance()->claimErrorLoggingToken();

            self::getInstance()->setConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN', $response->accessToken);
            self::getInstance()->setConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT', strtotime($response->expiresAt));
            self::getInstance()->persist();
        } catch (Throwable) {
            // The token claim is best-effort; back off so the failure is not retried on every request.
            self::startTokenClaimCooldown();
        }
    }

    /**
     * @return string[]
     */
    public static function getIgnoredStatuses(): array
    {
        return self::getStatusList('COMFINO_IGNORED_STATUSES', StatusManager::DEFAULT_IGNORED_STATUSES);
    }

    /**
     * @return string[]
     */
    public static function getForbiddenStatuses(): array
    {
        return self::getStatusList('COMFINO_FORBIDDEN_STATUSES', StatusManager::DEFAULT_FORBIDDEN_STATUSES);
    }

    /**
     * Returns the ComfinoPay status => order status map; a stored map with an
     * unknown status is ignored as a whole (the option is remotely writable).
     *
     * @return string[]
     */
    public static function getStatusMap(): array
    {
        $statusMap = self::getConfigurationValue('COMFINO_STATUS_MAP');

        try {
            return (array) self::getValueConstraints()['COMFINO_STATUS_MAP']->apply('COMFINO_STATUS_MAP', $statusMap);
        } catch (InvalidConfigValue) {
            return ShopStatusManager::defaultStatusMap();
        }
    }

    /**
     * Magento order status set when an order is submitted to ComfinoPay (default comfino_created).
     */
    public static function getInitialOrderStatus(): string
    {
        $initialStatus = self::getConfigurationValue('COMFINO_INITIAL_ORDER_STATUS');

        return is_string($initialStatus) && isset(ShopStatusManager::CUSTOM_STATUS_LABELS[$initialStatus])
            ? $initialStatus
            : ShopStatusManager::customStatusMap()[OrderStatus::CREATED->value];
    }

    /**
     * Returns the connection timeout, the transfer timeout (seconds) and the number of connection attempts for the API
     * client, bounded as RemoteValueConstraints::clampTimeouts() describes.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function getApiTimeouts(): array
    {
        return RemoteValueConstraints::clampTimeouts(
            self::getConfigurationValue('COMFINO_API_CONNECT_TIMEOUT', 3),
            self::getConfigurationValue('COMFINO_API_TIMEOUT', 5),
            self::getConfigurationValue('COMFINO_API_CONNECT_NUM_ATTEMPTS', 3)
        );
    }

    /**
     * Timeouts of the single-attempt client used by the outbound request queue.
     *
     * @return array{0: int, 1: int}
     */
    public static function getQueueApiTimeouts(): array
    {
        [$connectTimeout, $transferTimeout] = RemoteValueConstraints::clampTimeouts(
            self::getConfigurationValue('COMFINO_API_QUEUE_CONNECT_TIMEOUT', 1),
            self::getConfigurationValue('COMFINO_API_QUEUE_TIMEOUT', 2)
        );

        return [$connectTimeout, $transferTimeout];
    }

    /**
     * Returns a custom stylesheet URL option, or null when it is empty or not an https:// URL on the shop's domain.
     * The options are remotely writable, so the stored value is checked again before it reaches a page.
     */
    public static function getCustomCssUrl(string $optionName): ?string
    {
        $url = self::getConfigurationValue($optionName);

        return is_string($url) && trim($url) !== '' && CssUrlConstraint::isValid($url, self::getShopHosts(), self::useDevEnvVars())
            ? trim($url)
            : null;
    }

    /**
     * Hosts ("host" or "host:port") of every store's base URLs, secure and unsecure.
     *
     * @return string[]
     */
    public static function getShopHosts(): array
    {
        if (self::$shopHosts === null) {
            $hosts = [];

            try {
                /** @var StoreManagerInterface $storeManager */
                $storeManager = ObjectManager::getInstance()->get(StoreManagerInterface::class);

                foreach ($storeManager->getStores(true) as $store) {
                    if (!$store instanceof Store) {
                        continue;
                    }

                    foreach ([false, true] as $secure) {
                        $parts = parse_url((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure));

                        if (is_array($parts) && !empty($parts['host'])) {
                            $hosts[] = CssUrlConstraint::normalizeHost($parts['host'], $parts['port'] ?? null);
                        }
                    }
                }
            } catch (Throwable) {
                // No store data available: no host is accepted.
            }

            self::$shopHosts = array_values(array_unique($hosts));
        }

        return self::$shopHosts;
    }

    /**
     * @return array<string, ConfigValueConstraintInterface>
     */
    public static function getValueConstraints(): array
    {
        return self::$valueConstraints ??= RemoteValueConstraints::build(
            static fn (): array => self::getShopHosts(),
            static fn (): bool => self::useDevEnvVars()
        );
    }

    /**
     * @param string[] $optionsToReturn
     *
     * @return array<string, mixed>
     */
    public static function getConfigurationValues(string $optionsGroup, array $optionsToReturn = []): array
    {
        if (!array_key_exists($optionsGroup, self::CONFIG_OPTIONS)) {
            return [];
        }

        return count($optionsToReturn)
            ? self::getInstance()->getConfigurationValues($optionsToReturn)
            : self::getInstance()->getConfigurationValues(array_keys(self::CONFIG_OPTIONS[$optionsGroup]));
    }

    /** @return array<string, mixed> */
    public static function getWidgetVariables(?int $productId = null, ?Product $product = null, ?Cart $shopCart = null): array
    {
        /** @var Data $dataHelper */
        $dataHelper = ObjectManager::getInstance()->get(Data::class);
        $productData = self::resolveProductData($productId, $product, $shopCart);

        try {
            $currency = ObjectManager::getInstance()
                ->get(StoreManagerInterface::class)
                ->getStore()
                ->getCurrentCurrencyCode();
        } catch (Throwable) {
            $currency = 'PLN';
        }

        $pageContext = ['type' => 'product'];

        if (!empty($productData['product_id'])) {
            $pageContext['productId'] = $productData['product_id'];
        }

        /* Browser-safe shop environment - minimal facts the CDN-side widget SDK uses to pick a selector profile
           (platform, theme.family, locale, page context). Sensitive version / edition / capability data goes
           server-to-server via the dedicated shop-environment reporting endpoint, NOT through this frontend payload. */
        return [
            'PRODUCT_ID' => $productData['product_id'],
            'PRODUCT_PRICE' => $productData['price'],
            'AVAILABLE_PRODUCT_TYPES' => $productData['available_product_types'],
            'PRODUCT_CART_DETAILS' => $productData['product_cart_details'],
            'LANGUAGE' => $dataHelper->getShopLanguage(),
            'CURRENCY' => $currency,
            'SHOP_ENVIRONMENT' => ObjectManager::getInstance()
                ->get(AbstractShopEnvironmentBuilder::class)
                ->buildForFrontend($pageContext),
        ];
    }

    /**
     * Per-visitor widget values (track ID, frontend logging token). Kept out of getWidgetConfig() because that config is
     * embedded in full-page-cached pages; the widget template reads them from the uncached comfino/widget/session endpoint.
     *
     * @return array{trackId: string|null, loggingToken: string|null}
     */
    public static function getWidgetSessionValues(): array
    {
        $widgetKey = (string) self::getWidgetKey();
        $accessToken = self::getErrorLoggingAccessToken();

        try {
            $loggingToken = $widgetKey !== '' && $accessToken !== ''
                ? (new FrontendLogAuthKeyGenerator())->generateToken($widgetKey, $accessToken)
                : '';
        } catch (Throwable) {
            $loggingToken = '';
        }

        try {
            $trackId = ApiClient::getInstance()->getTrackId();
        } catch (Throwable) {
            $trackId = null;
        }

        return ['trackId' => $trackId ?: null, 'loggingToken' => $loggingToken ?: null];
    }

    /** @return array<string, mixed> */
    public static function getDefaultConfigurationValues(): array
    {
        return [
            'COMFINO_API_KEY' => '',
            'COMFINO_SANDBOX_API_KEY' => '',
            'COMFINO_PAYMENT_TEXT' => 'ComfinoPay',
            'COMFINO_MINIMAL_CART_AMOUNT' => 30,
            'COMFINO_USE_ORDER_REFERENCE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_DEBUG' => false,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_DEV_ENV_VARS' => false,
            'COMFINO_WEBHOOK_IP_ALLOWLIST_ENFORCE' => false,
            'COMFINO_DEBUG_LOG_REMOTE_READ' => true,
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => '',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' =>
                'INSTALLMENTS_ZERO_PERCENT,PAY_LATER,COMPANY_BNPL,COMPANY_INSTALLMENTS,LEASING,PAY_IN_PARTS',
            'COMFINO_PAYWALL_DIRECT_REDIRECT' => false,
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => '',
            'COMFINO_PAYWALL_TRACKING_CONSENT' => false,
            'COMFINO_WIDGET_ENABLED' => false,
            'COMFINO_WIDGET_KEY' => '',
            'COMFINO_WIDGET_PRICE_SELECTOR' => '[data-price-type="finalPrice"]',
            'COMFINO_WIDGET_PRICE_ATTRIBUTE' => 'data-price-amount',
            'COMFINO_WIDGET_TARGET_SELECTOR' => 'div.product-add-form',
            'COMFINO_WIDGET_PRICE_OBSERVER_SELECTOR' => '',
            'COMFINO_WIDGET_PRICE_OBSERVER_LEVEL' => 0,
            'COMFINO_WIDGET_TYPE' => 'standard',
            'COMFINO_WIDGET_OFFER_TYPES' => 'CONVENIENT_INSTALLMENTS',
            'COMFINO_WIDGET_EMBED_METHOD' => 'INSERT_INTO_LAST',
            'COMFINO_WIDGET_SHOW_PROVIDER_LOGOS' => false,
            'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL' => '',
            'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL' => '',
            'COMFINO_SDK_SCRIPT_VERSION' => SdkUrlBuilder::DEFAULT_SDK_VERSION,
            'COMFINO_PROD_CAT_CACHE_TTL' => 3600,
            'COMFINO_INITIAL_ORDER_STATUS' => ShopStatusManager::customStatusMap()[OrderStatus::CREATED->value],
            'COMFINO_IGNORED_STATUSES' => implode(',', array_map(
                static fn (OrderStatusInterface $s): string => $s->getValue(),
                StatusManager::DEFAULT_IGNORED_STATUSES
            )),
            'COMFINO_FORBIDDEN_STATUSES' => implode(',', array_map(
                static fn (OrderStatusInterface $s): string => $s->getValue(),
                StatusManager::DEFAULT_FORBIDDEN_STATUSES
            )),
            'COMFINO_STATUS_MAP' => (new JsonSerializer())->serialize(ShopStatusManager::defaultStatusMap()),
            'COMFINO_API_CONNECT_TIMEOUT' => 3,
            'COMFINO_API_TIMEOUT' => 5,
            'COMFINO_API_CONNECT_NUM_ATTEMPTS' => 3,
            'COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => false,
            'COMFINO_REMOTE_FLAGS' => '',
            'COMFINO_RETRY_QUEUE_ENABLED' => true,
            'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS' => 10,
            'COMFINO_RETRY_QUEUE_BATCH_SIZE' => 20,
            'COMFINO_RETRY_QUEUE_COOLDOWN' => 300,
            'COMFINO_API_QUEUE_CONNECT_TIMEOUT' => 1,
            'COMFINO_API_QUEUE_TIMEOUT' => 2,
        ];
    }

    /**
     * Per-shop dev-override opt-in passed to {@see SdkUrlBuilder}; the production guard lives in the SDK.
     */
    private static function devEnvVarsEnabled(): bool
    {
        return (bool) self::getConfigurationValue('COMFINO_DEV_ENV_VARS');
    }

    /** @return array<string, mixed> */
    private static function resolveProductData(?int $productId, ?Product $product = null, ?Cart $shopCart = null): array
    {
        $price = 'null';
        $productCartDetails = 'null';

        if ($productId !== null) {
            try {
                if ($product === null) {
                    /** @var ProductRepositoryInterface $productRepository */
                    $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
                    /** @var Product $product */
                    $product = $productRepository->getById($productId);
                }

                $price = $product->getFinalPrice();

                $shopCart ??= ObjectManager::getInstance()
                    ->get(OrderManager::class)
                    ->getShopCartFromProduct($product);

                $availableProductTypes = SettingsManager::getAllowedProductTypes(ProductListType::WIDGET->value, $shopCart, true);
                $productCartDetails = $shopCart->getAsArray();
            } catch (LocalizedException) {
                // Product isn't found or error - fall back to unfiltered product types.
                $availableProductTypes = SettingsManager::getProductTypesStrings(ProductListType::WIDGET->value);
            }
        } else {
            $availableProductTypes = SettingsManager::getProductTypesStrings(ProductListType::WIDGET->value);
        }

        return [
            'product_id' => $productId ?? 'null',
            'price' => $price,
            'available_product_types' => $availableProductTypes,
            'product_cart_details' => $productCartDetails,
        ];
    }

    /** @return array<string, int> */
    private static function getAvailableConfigOptions(): array
    {
        if (self::$availConfigOptions === null) {
            self::$availConfigOptions = array_merge(...array_values(self::CONFIG_OPTIONS));
        }

        return self::$availConfigOptions;
    }

    /**
     * Whether a recent token claim failed and the retry cooldown is still running. A cache failure resolves to false
     * (retry always) rather than suppressing the claim indefinitely.
     */
    private static function tokenClaimOnCooldown(): bool
    {
        try {
            return CacheManager::getPool()->getItem(self::ERROR_LOGGING_TOKEN_COOLDOWN_KEY)->isHit();
        } catch (Throwable) {
            return false;
        }
    }

    private static function startTokenClaimCooldown(): void
    {
        try {
            $pool = CacheManager::getPool();

            $cooldownItem = $pool->getItem(self::ERROR_LOGGING_TOKEN_COOLDOWN_KEY);
            $cooldownItem->set(true);
            $cooldownItem->expiresAfter(self::ERROR_LOGGING_TOKEN_COOLDOWN);

            $pool->save($cooldownItem);
        } catch (Throwable) {
            // Cache unavailable - nothing to back off with.
        }
    }

    /**
     * @param OrderStatusInterface[] $defaultStatuses
     *
     * @return string[]
     */
    private static function getStatusList(string $optionName, array $defaultStatuses): array
    {
        $statuses = self::getConfigurationValue($optionName);

        if ($statuses !== null) {
            try {
                return (array) self::getValueConstraints()[$optionName]->apply($optionName, $statuses);
            } catch (InvalidConfigValue) {
                // A list with an unknown status is ignored as a whole; the default list applies.
            }
        }

        return array_map(static fn (OrderStatusInterface $status): string => $status->getValue(), $defaultStatuses);
    }
}

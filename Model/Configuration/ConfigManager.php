<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Auth\FrontendLogAuthKeyGenerator;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\CategoryTree\BuildStrategy;
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
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Facade over Comfino\Backend\Configuration\ConfigurationManager for Magento.
 *
 * @see ConfigurationManager
 */
class ConfigManager
{
    /*
     * Widget banner target selectors used as theme-family-aware defaults. The stored value (etc/config.xml) is a
     * Luma selector: .product-info-main is Luma's right-hand info column, so INSERT_INTO_LAST lands the banner under
     * the buy box. In Hyva that same class wraps the entire product view, so the banner falls to the page bottom;
     * #product_addtocart_form (the standard Magento add-to-cart form id, present in Hyva) restores the buy-box
     * position. resolveWidgetTargetSelector() swaps the default only when the shop still uses a Luma default, so an
     * admin-set custom selector is never overridden.
     */
    private const WIDGET_TARGET_SELECTOR_HYVA_DEFAULT = '#product_addtocart_form';
    private const WIDGET_TARGET_SELECTOR_LUMA_DEFAULTS = ['div.product-info-main', 'div.product-add-form'];

    public const CONFIG_OPTIONS = [
        'payment_settings' => [
            'COMFINO_API_KEY' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_PAYMENT_TEXT' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
            'COMFINO_MINIMAL_CART_AMOUNT' => ConfigurationManager::OPT_VALUE_TYPE_FLOAT,
            'COMFINO_USE_ORDER_REFERENCE' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_PAYWALL_DIRECT_REDIRECT' => ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => ConfigurationManager::OPT_VALUE_TYPE_STRING,
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
    ];

    private static ?ConfigurationManager $configurationManager = null;
    /** @var array<string, int>|null */
    private static ?array $availConfigOptions = null;
    private static ?CollectionFactory $collectionFactory = null;

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
                    ObjectManager::getInstance()->get(TypeListInterface::class)
                ),
                new JsonSerializer()
            );

            self::$configurationManager->setDefaults(self::getDefaultConfigurationValues());
        }

        return self::$configurationManager;
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

        if (($serverAddr = getenv('SERVER_ADDR')) === false) {
            $serverAddr = 'n/a';
        }

        $envFields = [
            'plugin_version' => $dataHelper->getModuleVersion(),
            'plugin_build_ts' => Data::BUILD_TS,
            'shop_version' => $dataHelper->getShopVersion(),
            'php_version' => PHP_VERSION,
            'server_software' => $serverSoftware,
            'server_name' => $serverName,
            'server_addr' => $serverAddr,
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

    public static function useDevEnvVars(): bool
    {
        return getenv('COMFINO_DEV_ENV') === 'TRUE' &&
            (bool) self::getInstance()->getOptionWithDefault('COMFINO_DEV_ENV_VARS');
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
     * CDN URL of the Magento product-page widget script served from the SDK host at /product/v1/.
     * The product-page sibling of the checkout glue: the classic-IIFE script reads the
     * `#comfino-widget-config` JSON block and calls sdk.bootstrapWidget() (WIDGET_BRIDGE_MIGRATION_PLAN §3).
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
     * CDN URL of the single, SDK-hosted Comfino brand logo used as the default payment-tile placeholder across all shop
     * plugins/platforms. Rendered by the KO template as the tile's initial logo; the SDK renderer adopts it and swaps
     * its `src` at runtime (to the auth-gated API Comfino logo).
     *
     * Uses the dedicated SDK CDN host directly (same host as getProductWidgetScriptUrl() above), independent of the
     * legacy widget.* host getSdkScriptUrl()/SdkUrlBuilder still resolve for the SDK *script* — the image host and the
     * script host are different for Luma/Hyvä. Honors the same COMFINO_DEV_ENV_VARS opt-in and COMFINO_DEV_SDK_CDN_BASE_URL
     * override as the other SDK URLs.
     */
    public static function getDefaultLogoUrl(): string
    {
        return SdkUrlBuilder::getDefaultLogoUrl(self::isSandboxMode(), self::devEnvVarsEnabled());
    }

    /**
     * Assembles the product-page widget config consumed by the CDN product widget script — the WidgetConfig contract
     * emitted into the `#comfino-widget-config` JSON block. Null values are dropped, so omitted options fall through
     * to the SDK / CDN-profile defaults.
     *
     * @return array<string, mixed>
     */
    public static function getWidgetConfig(?int $productId): array
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
                'COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL',
                'COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL',
            ]
        );

        $variables = self::getWidgetVariables($productId);

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
            'loggingToken' => $variables['LOGGING_TOKEN'] ?? null,
            'trackId' => $variables['TRACK_ID'] ?? null,
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
            'bannerCssUrl' => ($settings['COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL'] ?? '') ?: null,
            'calculatorCssUrl' => ($settings['COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL'] ?? '') ?: null,
            'price' => $price,
            'productId' => $notNull($variables['PRODUCT_ID'] ?? null),
            'availableProductTypes' => $variables['AVAILABLE_PRODUCT_TYPES'] ?? null,
            'productCartDetails' => $notNull($variables['PRODUCT_CART_DETAILS'] ?? null),
            'language' => $variables['LANGUAGE'] ?? null,
            'currency' => $variables['CURRENCY'] ?? null,
            'shopEnvironment' => $variables['SHOP_ENVIRONMENT'] ?? null,
        ];

        return array_filter($config, static fn ($value) => $value !== null);
    }

    /**
     * Resolves the effective widget banner target selector for the active theme family.
     *
     * The stored default (etc/config.xml) is a Luma selector that only positions the banner correctly on Luma-family
     * themes. On Hyva the same selector matches the whole product view, so the banner drops to the page bottom; when
     * the shop is still on a Luma default we substitute {@see WIDGET_TARGET_SELECTOR_HYVA_DEFAULT}. A selector the
     * merchant has explicitly customized (i.e. not one of the known Luma defaults) is always left untouched.
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
     * SHA3-256 HMAC handed to the SDK as `paymentMethodItem.auth` so CDN logo requests can be authenticated. Derived
     * from the API key — must never reach the DOM as a `data-*` attribute.
     *
     * Returns the bare base64 string (no URL-encoding): the SDK percent-encodes the value itself when it builds the
     * CDN URL, so emitting the raw payload here avoids double-encoding.
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

    public static function refreshErrorLoggingTokenIfNeeded(): void
    {
        if (empty(self::getApiKey())) {
            return;
        }

        if (self::getErrorLoggingAccessToken() !== '' && self::getErrorLoggingAccessTokenExpiresAt() > time() + 3600) {
            return;
        }

        try {
            $response = ApiClient::getInstance()->claimErrorLoggingToken();

            self::getInstance()->setConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN', $response->accessToken);
            self::getInstance()->setConfigurationValue('COMFINO_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT', strtotime($response->expiresAt));
            self::getInstance()->persist();
        } catch (\Throwable) {
            // Silently ignore — CETS token claim is best-effort.
        }
    }

    /**
     * @return string[]
     */
    public static function getIgnoredStatuses(): array
    {
        $ignoredStatuses = self::getConfigurationValue('COMFINO_IGNORED_STATUSES');

        if (is_array($ignoredStatuses)) {
            return $ignoredStatuses;
        }

        return array_map(
            static fn (OrderStatusInterface $s): string => $s->getValue(),
            StatusManager::DEFAULT_IGNORED_STATUSES
        );
    }

    /**
     * @return string[]
     */
    public static function getForbiddenStatuses(): array
    {
        $forbiddenStatuses = self::getConfigurationValue('COMFINO_FORBIDDEN_STATUSES');

        if (is_array($forbiddenStatuses)) {
            return $forbiddenStatuses;
        }

        return array_map(
            static fn (OrderStatusInterface $status): string => $status->getValue(),
            StatusManager::DEFAULT_FORBIDDEN_STATUSES
        );
    }

    /**
     * @return string[]
     */
    public static function getStatusMap(): array
    {
        if (!is_array($statusMap = self::getConfigurationValue('COMFINO_STATUS_MAP'))) {
            $statusMap = null;
        }

        return $statusMap ?? ShopStatusManager::defaultStatusMap();
    }

    /**
     * Returns the Magento order status code to set when an order is submitted to Comfino.
     * Defaults to comfino_created; can be overridden by the shop owner via COMFINO_INITIAL_ORDER_STATUS.
     */
    public static function getInitialOrderStatus(): string
    {
        return (string) (
            self::getConfigurationValue('COMFINO_INITIAL_ORDER_STATUS')
                ?: ShopStatusManager::customStatusMap()[OrderStatus::CREATED->value]
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
    public static function getWidgetVariables(?int $productId = null): array
    {
        /** @var Data $dataHelper */
        $dataHelper = ObjectManager::getInstance()->get(Data::class);
        $productData = self::resolveProductData($productId);

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

        $widgetKey = (string) self::getWidgetKey();
        $accessToken = self::getErrorLoggingAccessToken();

        try {
            $loggingToken = $widgetKey !== '' && $accessToken !== ''
                ? (new FrontendLogAuthKeyGenerator())->generateToken($widgetKey, $accessToken)
                : '';
        } catch (Throwable) {
            $loggingToken = '';
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
            'LOGGING_TOKEN' => $loggingToken,
            'TRACK_ID' => ApiClient::getInstance()->getTrackId(),
        ];
    }

    /** @return array<string, mixed> */
    public static function getDefaultConfigurationValues(): array
    {
        return [
            'COMFINO_PAYMENT_TEXT' => 'Comfino',
            'COMFINO_MINIMAL_CART_AMOUNT' => 30,
            'COMFINO_USE_ORDER_REFERENCE' => false,
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_DEBUG' => false,
            'COMFINO_SERVICE_MODE' => false,
            'COMFINO_DEV_ENV_VARS' => false,
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => '',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' =>
                'INSTALLMENTS_ZERO_PERCENT,PAY_LATER,COMPANY_BNPL,COMPANY_INSTALLMENTS,LEASING,PAY_IN_PARTS',
            'COMFINO_PAYWALL_DIRECT_REDIRECT' => false,
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => '',
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
            'COMFINO_RETRY_QUEUE_ENABLED' => true,
            'COMFINO_RETRY_QUEUE_MAX_ATTEMPTS' => 10,
            'COMFINO_RETRY_QUEUE_BATCH_SIZE' => 20,
            'COMFINO_RETRY_QUEUE_COOLDOWN' => 300,
            'COMFINO_API_QUEUE_CONNECT_TIMEOUT' => 1,
            'COMFINO_API_QUEUE_TIMEOUT' => 2,
        ];
    }

    /**
     * Per-shop dev-override opt-in passed to {@see SdkUrlBuilder}. The hard production guard (the COMFINO_DEV_ENV
     * server env var) lives in the SDK, so this only reflects the shop setting.
     */
    private static function devEnvVarsEnabled(): bool
    {
        return (bool) self::getConfigurationValue('COMFINO_DEV_ENV_VARS');
    }

    /** @return array<string, mixed> */
    private static function resolveProductData(?int $productId): array
    {
        $price = 'null';
        $productCartDetails = 'null';

        if ($productId !== null) {
            try {
                /** @var ProductRepositoryInterface $productRepository */
                $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
                /** @var Product $product */
                $product = $productRepository->getById($productId);

                $price = $product->getFinalPrice();

                /** @var Cart $shopCart */
                $shopCart = ObjectManager::getInstance()
                    ->get(OrderManager::class)
                    ->getShopCartFromProduct($product);

                $availableProductTypes = SettingsManager::getAllowedProductTypes(
                    ProductListType::WIDGET->value,
                    $shopCart,
                    true
                );
                $productCartDetails = $shopCart->getAsArray();
            } catch (LocalizedException) {
                // Product isn't found or error - fall back to unfiltered product types.
                $availableProductTypes = SettingsManager::getProductTypesStrings(
                    ProductListType::WIDGET->value
                );
            }
        } else {
            $availableProductTypes = SettingsManager::getProductTypesStrings(
                ProductListType::WIDGET->value
            );
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
}

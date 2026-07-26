<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Helper
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Helper;

use Comfino\ComfinoGateway\Model\Version;
use Comfino\Platform\PlatformInfoInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class Data extends AbstractHelper
{
    // Payment settings
    public const XML_PATH_API_KEY = 'payment/comfino/api_key';
    public const XML_PATH_PAYMENT_TEXT = 'payment/comfino/payment_text';
    public const XML_PATH_MINIMAL_CART_AMOUNT = 'payment/comfino/min_order_total';
    public const XML_PATH_USE_ORDER_REFERENCE = 'payment/comfino/use_order_reference';
    public const XML_PATH_PAYWALL_DIRECT_REDIRECT = 'payment/comfino/paywall_direct_redirect';
    public const XML_PATH_PAYWALL_CUSTOM_CSS_URL = 'payment/comfino/paywall_custom_css_url';
    // Sale settings
    public const XML_PATH_ALLOWED_PRODUCTS_CONFIG = 'payment/comfino/allowed_products_config';
    public const XML_PATH_PRODUCT_CATEGORY_FILTERS = 'payment/comfino/product_category_filters';
    // Widget settings
    public const XML_PATH_WIDGET_ENABLED = 'payment/comfino/widget_enabled';
    public const XML_PATH_WIDGET_KEY = 'payment/comfino/widget_key';
    public const XML_PATH_WIDGET_PRICE_SELECTOR = 'payment/comfino/widget_price_selector';
    public const XML_PATH_WIDGET_PRICE_ATTRIBUTE = 'payment/comfino/widget_price_attribute';
    public const XML_PATH_WIDGET_TARGET_SELECTOR = 'payment/comfino/widget_target_selector';
    public const XML_PATH_WIDGET_PRICE_OBSERVER_SELECTOR = 'payment/comfino/widget_price_observer_selector';
    public const XML_PATH_WIDGET_PRICE_OBSERVER_LEVEL = 'payment/comfino/widget_price_observer_level';
    public const XML_PATH_WIDGET_TYPE = 'payment/comfino/widget_type';
    public const XML_PATH_WIDGET_OFFER_TYPE = 'payment/comfino/widget_offer_type';
    public const XML_PATH_WIDGET_EMBED_METHOD = 'payment/comfino/widget_embed_method';
    public const XML_PATH_WIDGET_SHOW_PROVIDER_LOGOS = 'payment/comfino/widget_show_provider_logos';
    public const XML_PATH_WIDGET_CUSTOM_BANNER_CSS_URL = 'payment/comfino/widget_custom_banner_css_url';
    public const XML_PATH_WIDGET_CUSTOM_CALCULATOR_CSS_URL = 'payment/comfino/widget_custom_calculator_css_url';
    // Developer settings
    public const XML_PATH_SANDBOX_ENABLED = 'payment/comfino/sandbox';
    public const XML_PATH_SANDBOX_API_KEY = 'payment/comfino/sandbox_api_key';
    public const XML_PATH_DEBUG = 'payment/comfino/debug';
    public const XML_PATH_SERVICE_MODE = 'payment/comfino/service_mode';
    public const XML_PATH_DEV_ENV_VARS = 'payment/comfino/dev_env_vars';
    // Hidden settings
    public const XML_PATH_SDK_SCRIPT_VERSION = 'payment/comfino/sdk_script_version';
    public const XML_PATH_CAT_FILTER_AVAIL_PROD_TYPES = 'payment/comfino/cat_filter_avail_prod_types';
    public const XML_PATH_IGNORED_STATUSES = 'payment/comfino/ignored_statuses';
    public const XML_PATH_FORBIDDEN_STATUSES = 'payment/comfino/forbidden_statuses';
    public const XML_PATH_STATUS_MAP = 'payment/comfino/status_map';
    public const XML_PATH_API_CONNECT_TIMEOUT = 'payment/comfino/api_connect_timeout';
    public const XML_PATH_API_TIMEOUT = 'payment/comfino/api_timeout';
    public const XML_PATH_API_CONNECT_NUM_ATTEMPTS = 'payment/comfino/api_connect_num_attempts';
    public const XML_PATH_PROD_CAT_CACHE_TTL = 'payment/comfino/prod_cat_cache_ttl';
    public const XML_PATH_INITIAL_ORDER_STATUS = 'payment/comfino/initial_order_status';
    public const XML_PATH_ALLOWED_PRODUCTS_CONFIG_ENABLED = 'payment/comfino/allowed_products_config_enabled';
    public const XML_PATH_ERROR_LOGGING_ACCESS_TOKEN = 'payment/comfino/error_logging_access_token';
    public const XML_PATH_ERROR_LOGGING_ACCESS_TOKEN_EXPIRES_AT = 'payment/comfino/error_logging_access_token_expires_at';
    // Outbound request queue settings
    public const XML_PATH_RETRY_QUEUE_ENABLED = 'payment/comfino/retry_queue_enabled';
    public const XML_PATH_RETRY_QUEUE_MAX_ATTEMPTS = 'payment/comfino/retry_queue_max_attempts';
    public const XML_PATH_RETRY_QUEUE_BATCH_SIZE = 'payment/comfino/retry_queue_batch_size';
    public const XML_PATH_RETRY_QUEUE_COOLDOWN = 'payment/comfino/retry_queue_cooldown';
    public const XML_PATH_API_QUEUE_CONNECT_TIMEOUT = 'payment/comfino/api_queue_connect_timeout';
    public const XML_PATH_API_QUEUE_TIMEOUT = 'payment/comfino/api_queue_timeout';

    public const BUILD_TS = 1785058631;

    private PlatformInfoInterface $platformInfo;

    public function __construct(Context $context, PlatformInfoInterface $platformInfo)
    {
        $this->platformInfo = $platformInfo;

        parent::__construct($context);
    }

    public function getModuleVersion(): string
    {
        return Version::VERSION;
    }

    public function getHyvaCheckoutModuleVersion(): ?string
    {
        if (class_exists('Comfino\\ComfinoGatewayHyvaCheckout\\Model\\Version')) {
            return \Comfino\ComfinoGatewayHyvaCheckout\Model\Version::VERSION;
        }

        return null;
    }

    /**
     * Returns shop platform version.
     */
    public function getShopVersion(): string
    {
        return $this->platformInfo->getVersion();
    }

    /**
     * Returns DBMS engine name and version (e.g. "MariaDB 10.6.12" or "MySQL 8.0.32").
     */
    public function getDatabaseInfo(): string
    {
        $version = $this->platformInfo->getDatabaseVersion();

        if ($version === '' || $version === 'unknown') {
            return 'n/a';
        }

        if (stripos($version, 'mariadb') !== false) {
            return 'MariaDB ' . preg_replace('/-MariaDB.*/i', '', $version);
        }

        return 'MySQL ' . preg_replace('/-.+$/', '', $version);
    }

    public function getShopDomain(): string
    {
        return $this->platformInfo->getDomain();
    }

    public function getShopLanguage(): string
    {
        return $this->platformInfo->getLanguage();
    }
}

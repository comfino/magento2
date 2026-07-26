<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Throwable;

class ConfigObserver implements ObserverInterface
{
    /**
     * Config paths that feed the product-page widget config (`Model\Configuration\ConfigManager::getWidgetConfig()`
     * / `SettingsManager::getAllowedProductTypes()`). Full-page-cached product pages bake this config into their
     * markup at render time, so changing any of these paths must bust `full_page` — Magento's own config-save flow
     * only cleans the `config` cache type, and product-page HTML has no cache tag tying it back to these settings.
     */
    private const WIDGET_CONFIG_PATHS = [
        Data::XML_PATH_PRODUCT_CATEGORY_FILTERS,
        Data::XML_PATH_CAT_FILTER_AVAIL_PROD_TYPES,
        Data::XML_PATH_MINIMAL_CART_AMOUNT,
        Data::XML_PATH_WIDGET_ENABLED,
        Data::XML_PATH_WIDGET_KEY,
        Data::XML_PATH_WIDGET_PRICE_SELECTOR,
        Data::XML_PATH_WIDGET_PRICE_ATTRIBUTE,
        Data::XML_PATH_WIDGET_TARGET_SELECTOR,
        Data::XML_PATH_WIDGET_PRICE_OBSERVER_SELECTOR,
        Data::XML_PATH_WIDGET_PRICE_OBSERVER_LEVEL,
        Data::XML_PATH_WIDGET_TYPE,
        Data::XML_PATH_WIDGET_OFFER_TYPE,
        Data::XML_PATH_WIDGET_EMBED_METHOD,
        Data::XML_PATH_WIDGET_SHOW_PROVIDER_LOGOS,
        Data::XML_PATH_WIDGET_CUSTOM_BANNER_CSS_URL,
        Data::XML_PATH_WIDGET_CUSTOM_CALCULATOR_CSS_URL,
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        $isSandbox = (bool) $this->scopeConfig->getValue(Data::XML_PATH_SANDBOX_ENABLED, ScopeInterface::SCOPE_STORE);
        $activeApiKey = trim((string) $this->scopeConfig->getValue(
            $isSandbox ? Data::XML_PATH_SANDBOX_API_KEY : Data::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE
        ));

        /* Field validation (required, numeric, URL format) is handled by backend models that fire before the save is
           committed. This observer only performs the API-level check and fetches the widget key post-commit. */
        if (!empty($activeApiKey)) {
            try {
                $apiClient = ApiClient::getInstance($isSandbox, $activeApiKey);

                // Verify key is accepted — throws AuthorizationError on 401, AccessDenied on 403.
                $apiClient->isShopAccountActive();

                // Persist widget key returned by API.
                try {
                    $this->configWriter->save(Data::XML_PATH_WIDGET_KEY, $apiClient->getWidgetKey());
                } catch (Throwable $e) {
                    ApiClient::processApiError(
                        OperationContext::Configuration,
                        ErrorCategory::ApiError,
                        ErrorSeverity::Warning,
                        $e
                    );

                    $this->messageManager->addErrorMessage($e->getMessage());
                }
            } catch (AuthorizationError | AccessDenied $e) {
                ApiClient::processApiError(
                    OperationContext::Configuration,
                    ErrorCategory::ApiError,
                    ErrorSeverity::Warning,
                    $e
                );

                $this->messageManager->addWarningMessage(__('API key %1 is not valid.', $activeApiKey));
            } catch (Throwable $e) {
                ApiClient::processApiError(
                    OperationContext::Configuration,
                    ErrorCategory::ApiError,
                    ErrorSeverity::Warning,
                    $e
                );

                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        $this->cacheTypeList->cleanType('config');

        /* getEvent() is typed non-nullable in Magento's own PHPDoc, but a bare/test-constructed Observer never had
           setEvent() called and returns null in practice — the nullsafe guard reflects reality over the PHPDoc. */
        // @phpstan-ignore nullsafe.neverNull
        $changedPaths = (array) ($observer->getEvent()?->getData('changed_paths') ?? []);

        if (array_intersect($changedPaths, self::WIDGET_CONFIG_PATHS) !== []) {
            $this->cacheTypeList->cleanType('full_page');
        }
    }
}

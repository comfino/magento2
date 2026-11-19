<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Observer;

use Comfino\Api\Client;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Config\Backend\ReplacedApiKeys;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

class ConfigObserver implements ObserverInterface
{
    /* Config paths feeding the product-page widget config. Full-page-cached product pages bake it into their markup and
       Magento's config save only cleans the `config` cache, so changing any of these must bust `full_page`. */
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
        private readonly ManagerInterface $messageManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopedApiCredentials $apiCredentials
    ) {
    }

    /**
     * Validates the API key of the config scope that was just saved and persists the matching widget key into that same
     * scope, then re-syncs the widget keys of the websites/store views below it.
     */
    public function execute(Observer $observer): void
    {
        /* getEvent() is typed non-nullable in Magento's own PHPDoc, but a bare/test-constructed Observer never had
           setEvent() called and returns null in practice — the nullsafe calls below reflect reality over the PHPDoc. */
        /** @var Event|null $event */
        $event = $observer->getEvent();

        [$scope, $scopeId] = $this->apiCredentials->resolveScope(
            (string) ($event?->getData('website') ?? ''),
            (string) ($event?->getData('store') ?? '')
        );

        $credentials = $this->apiCredentials->getCredentials($scope, $scopeId);
        $activeApiKey = $credentials[1];

        /* Field validation (required, numeric, URL format) is handled by backend models that fire before the save is
           committed. This observer only performs the API-level check and fetches the widget key post-commit. */
        if (!empty($activeApiKey)) {
            try {
                $apiClient = $this->apiCredentials->getApiClient($credentials);

                // Verify key is accepted — throws AuthorizationError on 401, AccessDenied on 403.
                $apiClient->isShopAccountActive();

                try {
                    $this->syncWidgetKey($scope, $scopeId, $credentials, $apiClient);
                } catch (Throwable $e) {
                    ApiClient::processApiError(OperationContext::Configuration, ErrorCategory::ApiError, ErrorSeverity::Warning, $e);

                    $this->messageManager->addErrorMessage($e->getMessage());
                }
            } catch (AuthorizationError | AccessDenied $e) {
                ApiClient::processApiError(OperationContext::Configuration, ErrorCategory::ApiError, ErrorSeverity::Warning, $e);

                if ($this->restorePreviousApiKey($scope, $scopeId, $credentials[0])) {
                    $this->messageManager->addWarningMessage(__('API key is not valid. The previously saved key was kept.'));
                } else {
                    $this->messageManager->addWarningMessage(__('API key is not valid.'));
                }
            } catch (Throwable $e) {
                ApiClient::processApiError(OperationContext::Configuration, ErrorCategory::ApiError, ErrorSeverity::Warning, $e);

                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        /* A website/store view below the saved scope keeps its own settings, but what it inherits may just have changed:
           one with its own credentials may still be inheriting the widget key saved here (another account's), and one
           that now resolves to the same credentials as its parent may hold a stale override. */
        foreach ($this->getChildScopes($scope, $scopeId) as [$childScope, $childScopeId]) {
            try {
                $this->syncWidgetKey(
                    $childScope,
                    $childScopeId,
                    $this->apiCredentials->getCredentials($childScope, $childScopeId)
                );
            } catch (Throwable $e) {
                ApiClient::processApiError(
                    OperationContext::Configuration,
                    ErrorCategory::ApiError,
                    ErrorSeverity::Warning,
                    $e
                );
            }
        }

        $this->cacheTypeList->cleanType('config');

        $changedPaths = (array) ($event?->getData('changed_paths') ?? []);

        if (array_intersect($changedPaths, self::WIDGET_CONFIG_PATHS) !== []) {
            $this->cacheTypeList->cleanType('full_page');
        }
    }

    /**
     * Puts back the key this save replaced when the API rejected the new one, so a mistyped key does not stop payments.
     * Returns false when this save did not enter a new key for the scope (nothing to restore).
     */
    private function restorePreviousApiKey(string $scope, int $scopeId, bool $isSandbox): bool
    {
        $keyPath = $isSandbox ? Data::XML_PATH_SANDBOX_API_KEY : Data::XML_PATH_API_KEY;

        if (($previousValue = ReplacedApiKeys::previous($keyPath, $scope, $scopeId)) === null || trim($previousValue) === '') {
            return false;
        }

        $this->configWriter->save($keyPath, $previousValue, $scope, $scopeId);
        ReplacedApiKeys::reset();

        return true;
    }

    /**
     * Stores the widget key matching the scope's credentials in that scope, or drops the scope's widget key override
     * when the scope resolves to the same credentials as its parent (or has no API key), so it inherits the parent's.
     *
     * @throws NoSuchEntityException
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    private function syncWidgetKey(string $scope, int $scopeId, array $credentials, ?Client $apiClient = null): void
    {
        if ($credentials[1] === '' || $credentials === $this->apiCredentials->getParentCredentials($scope, $scopeId)) {
            $this->configWriter->delete(Data::XML_PATH_WIDGET_KEY, $scope, $scopeId);

            return;
        }

        $apiClient ??= $this->apiCredentials->getApiClient($credentials);

        $this->configWriter->save(Data::XML_PATH_WIDGET_KEY, $apiClient->getWidgetKey(), $scope, $scopeId);
    }

    /**
     * Websites and store views that inherit from the given scope: all of them for default config, the website's own
     * store views for a website, none for a store view.
     *
     * @return list<array{string, int}>
     */
    private function getChildScopes(string $scope, int $scopeId): array
    {
        if ($scope === ScopeInterface::SCOPE_STORES) {
            return [];
        }

        $childScopes = [];

        if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $childScopes[] = [ScopeInterface::SCOPE_WEBSITES, (int) $website->getId()];
            }
        }

        foreach ($this->storeManager->getStores() as $store) {
            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT || (int) $store->getWebsiteId() === $scopeId) {
                $childScopes[] = [ScopeInterface::SCOPE_STORES, (int) $store->getId()];
            }
        }

        return $childScopes;
    }
}

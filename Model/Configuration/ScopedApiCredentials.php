<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Przemysław Kałuziński <pkaluzinski@comfinopay.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Comfino\Api\Client;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\Frontend\SdkUrlBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the API credentials of an explicit config scope (default, website or store view) and builds the API client.
 *
 * ConfigManager / ApiClient defaults resolve against the ambient store, which in the backend is always the default
 * store view, so they would ignore the scope being edited. Credentials are [sandbox flag, trimmed API key, API host
 * override]; the host is included because the same key on a dev host belongs to a different account.
 */
class ScopedApiCredentials
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Maps admin scope-switcher website/store IDs (empty for default config) to a scope type and scope ID.
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function resolveScope(string $website, string $store): array
    {
        if ($store !== '') {
            return [ScopeInterface::SCOPE_STORES, (int) $this->storeManager->getStore($store)->getId()];
        }

        if ($website !== '') {
            return [ScopeInterface::SCOPE_WEBSITES, (int) $this->storeManager->getWebsite($website)->getId()];
        }

        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
    }

    /**
     * Returns the effective [sandbox flag, trimmed active API key, API host override] of the given scope.
     *
     * @return array{bool, string, string|null}
     */
    public function getCredentials(string $scope, int $scopeId): array
    {
        $scopeCode = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId;
        $isSandbox = (bool) $this->scopeConfig->getValue(Data::XML_PATH_SANDBOX_ENABLED, $scope, $scopeCode);
        $apiKey = ApiKeyCrypt::decrypt(
            $this->scopeConfig->getValue($isSandbox ? Data::XML_PATH_SANDBOX_API_KEY : Data::XML_PATH_API_KEY, $scope, $scopeCode),
            $this->encryptor
        );
        $apiHost = SdkUrlBuilder::getApiHostOverride(
            (bool) $this->scopeConfig->getValue(Data::XML_PATH_DEV_ENV_VARS, $scope, $scopeCode)
        );

        return [$isSandbox, $apiKey, $apiHost];
    }

    /**
     * Credentials of the scope's parent (store view -> website -> default); null for default config, which has none.
     *
     * @return array{bool, string, string|null}|null
     *
     * @throws NoSuchEntityException
     */
    public function getParentCredentials(string $scope, int $scopeId): ?array
    {
        return match ($scope) {
            ScopeInterface::SCOPE_STORES => $this->getCredentials(
                ScopeInterface::SCOPE_WEBSITES,
                (int) $this->storeManager->getStore($scopeId)->getWebsiteId()
            ),
            ScopeInterface::SCOPE_WEBSITES => $this->getCredentials(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0),
            default => null,
        };
    }

    /**
     * Returns the shared API client set up for the given credentials.
     *
     * @param array{bool, string, string|null} $credentials
     */
    public function getApiClient(array $credentials): Client
    {
        [$isSandbox, $apiKey, $apiHost] = $credentials;

        $apiClient = ApiClient::getInstance($isSandbox, $apiKey);

        /* getInstance() applies the API host override of the ambient store (the default store view in the backend), not
           of the scope being processed — set this scope's host explicitly, resetting an override it doesn't use. */
        $apiClient->setCustomApiBaseUrl($apiHost);

        return $apiClient;
    }
}

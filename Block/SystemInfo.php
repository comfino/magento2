<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block;

use Comfino\Api\CircuitBreaker\CircuitBreakerKey;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Gateway\Http\ApiService;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigChangeAudit;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\ComfinoGateway\Model\Queue\QueueHealth;
use Comfino\ComfinoGateway\Model\Queue\TenantKey;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use DateTime;
use Magento\Backend\Block\Template;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Throwable;

/**
 * Plugin diagnostics panel. Shop-specific values are read for the scope picked in the admin scope switcher, not the
 * ambient store (always the default store view in the backend).
 */
class SystemInfo extends Field
{
    private Data $helper;
    private ScopedApiCredentials $apiCredentials;
    private QueueHealth $queueHealth;
    private ?ConfigChangeAudit $configChangeAudit = null;

    protected $_template = 'Comfino_ComfinoGateway::system-info.phtml';

    /** @param array<string, mixed> $data */
    public function __construct(
        Data $helper,
        ScopedApiCredentials $apiCredentials,
        QueueHealth $queueHealth,
        Template\Context $context,
        array $data = [],
        ?ConfigChangeAudit $configChangeAudit = null
    ) {
        parent::__construct($context, $data);

        $this->configChangeAudit = $configChangeAudit;

        $this->queueHealth = $queueHealth;

        $this->helper = $helper;
        $this->apiCredentials = $apiCredentials;
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function render(AbstractElement $element): string
    {
        [$scope, $scopeId] = $this->apiCredentials->resolveScope(
            (string) $this->getRequest()->getParam('website', ''),
            (string) $this->getRequest()->getParam('store', '')
        );
        $scopeCode = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId;

        $credentials = $this->apiCredentials->getCredentials($scope, $scopeId);
        [$isSandbox, $apiKey] = $credentials;
        $apiClient = $this->apiCredentials->getApiClient($credentials);

        $moduleVersion = $this->helper->getModuleVersion();
        $buildTime = DateTime::createFromFormat('U', (string) Data::BUILD_TS)->format('Y-m-d H:i:s');

        $serverSoftware = getenv('SERVER_SOFTWARE');

        if ($serverSoftware === false) {
            $serverSoftware = 'n/a';
        }

        $infoMessages = [
            sprintf(
                'Magento ComfinoPay %s, Magento %s, PHP %s, web server %s, database %s',
                $moduleVersion,
                $this->helper->getShopVersion(),
                PHP_VERSION,
                $serverSoftware,
                $this->helper->getDatabaseInfo()
            ),
            $apiClient->getApiBaseUrl(),
            $buildTime,
            $this->getScopeDomain($scope, $scopeCode),
            (string) $this->_scopeConfig->getValue(Data::XML_PATH_WIDGET_KEY, $scope, $scopeCode),
        ];

        $successMessages = [];
        $warningMessages = [];
        $errorMessages = [];

        $updateInfo = UpdateManager::checkForUpdates($moduleVersion);

        if ($isSandbox) {
            $warningMessages[] = __('Developer mode is active. You are using test environment.');
            if (!empty($apiKey)) {
                try {
                    $apiClient->isShopAccountActive();
                    $successMessages[] = __('Test account is active.');
                } catch (AuthorizationError | AccessDenied $e) {
                    $errorMessages[] = $e->getMessage();
                    $errorMessages[] = __('Invalid test API key.');
                } catch (Throwable) {
                    $warningMessages[] = __('Test account is not active.');
                }
            } else {
                $errorMessages[] = __('Test API key not present.');
            }
        } elseif (!empty($apiKey)) {
            $successMessages[] = __('Production mode is active.');
            try {
                $apiClient->isShopAccountActive();
                $successMessages[] = __('Production account is active.');
            } catch (AuthorizationError | AccessDenied $e) {
                $errorMessages[] = $e->getMessage();
                $errorMessages[] = __('Invalid production API key.');
            } catch (Throwable) {
                $warningMessages[] = __('Production account is not active.');
            }
        } else {
            $errorMessages[] = __('Production API key not present.');
        }

        if (($overdueRequests = $this->queueHealth->getOverdueCount()) > 0) {
            $warningMessages[] = __(
                'Queued requests waiting for more than %1 minutes: %2. Check that Magento cron is running.',
                (int) (QueueHealth::OVERDUE_SECONDS / 60),
                $overdueRequests
            );
        }

        foreach ($this->queueHealth->getPausedStores() as $storeLabel => $reason) {
            $warningMessages[] = __('Sending queued requests is paused for %1 (%2).', $storeLabel, $reason);
        }

        foreach ($this->queueHealth->getDeadLetterCounts() as $operationType => $count) {
            $warningMessages[] = __('Queued requests dropped in the last 7 days (%1): %2.', $operationType, $count);
        }

        $devEnvActive = getenv('COMFINO_DEV_ENV') === 'TRUE';
        $devEnvVars = [];
        $hiddenItems = [];

        if ($devEnvActive) {
            $varNames = [
                'COMFINO_DEV_ENV',
                'COMFINO_DEV_API_HOST',
                'COMFINO_DEV_SDK_CDN_BASE_URL',
            ];

            foreach ($varNames as $varName) {
                $devEnvVars[$varName] = getenv($varName) ?: '';
            }

            $serializer = new JsonSerializer();

            $hiddenSettings = ConfigManager::getScopedInstance($scope, $scopeId)
                ->getConfigurationValues(array_keys(ConfigManager::CONFIG_OPTIONS['hidden_settings']));

            foreach ($hiddenSettings as $optionName => $optionValue) {
                if (is_array($optionValue) || is_bool($optionValue)) {
                    $optionValue = $serializer->serialize($optionValue);
                }

                $optionValue = (string) $optionValue;

                if (strlen($optionValue) > 200) {
                    $optionValue = substr($optionValue, 0, 200) . '...';
                }

                $hiddenItems[$optionName] = $optionValue;
            }
        }

        $this->setData([
            'info_messages' => $infoMessages,
            'success_messages' => $successMessages,
            'warning_messages' => $warningMessages,
            'error_messages' => $errorMessages,
            'update_info' => $updateInfo,
            'queue_pending' => $this->queueHealth->getPendingCount(),
            'api_health' => $this->getApiHealthLines($scope, $scopeId, $apiClient->getApiBaseUrl()),
            'config_changes' => array_reverse(array_slice($this->configChangeAudit?->getEntries() ?? [], -10)),
            'module_version' => $moduleVersion,
            'hyva_checkout_module_version' => $this->helper->getHyvaCheckoutModuleVersion(),
            'dev_env_active' => $devEnvActive,
            'dev_env_vars' => $devEnvVars,
            'hidden_items' => $hiddenItems,
            'use_dev_env_vars' => $devEnvActive &&
                (bool) $this->_scopeConfig->getValue(Data::XML_PATH_DEV_ENV_VARS, $scope, $scopeCode),
        ]);

        return $this->toHtml();
    }

    /**
     * Host of the scope's base URL (default config, a website or a store view), or an empty string when unknown.
     */
    private function getScopeDomain(string $scope, ?int $scopeCode): string
    {
        $baseUrl = (string) $this->_scopeConfig->getValue(Store::XML_PATH_UNSECURE_BASE_URL, $scope, $scopeCode);

        return (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');
    }

    /**
     * API health of the displayed scope's store: last call results, consecutive failures, circuit breaker state.
     *
     * @return string[]
     */
    private function getApiHealthLines(string $scope, int $scopeId, string $apiBaseUrl): array
    {
        $tenantKey = $scope === ScopeInterface::SCOPE_STORES ? TenantKey::forStore($scopeId) : (ErrorLogger::getScope() ?: null);
        $lines = [];
        $formatTime = static fn (?int $timestamp): string => $timestamp !== null ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC' : '-';

        if (($healthRecorder = ApiClient::getHealthRecorder()) !== null) {
            $health = $healthRecorder->getHealth($tenantKey);
            $lines[] = (string) __('Last successful API call: %1', $formatTime($health->lastSuccessAt));
            $lines[] = (string) __('Last failed API call: %1', $formatTime($health->lastFailureAt));
            $lines[] = (string) __('Consecutive failed API calls: %1', $health->consecutiveFailures);
        }

        $breaker = ApiClient::getCircuitBreaker();
        $host = (string) parse_url($apiBaseUrl, PHP_URL_HOST);

        if ($breaker !== null && $host !== '') {
            $snapshot = $breaker->inspect(CircuitBreakerKey::build($tenantKey, $host));
            $lines[] = (string) __('API connection breaker: %1', $snapshot->status->value);
        }

        if (($counter = ApiService::getFailedSignatureCounter()) !== null) {
            $lines[] = (string) __('Notifications with an invalid signature in the last 24 hours: %1', $counter->count($tenantKey));
        }

        return $lines;
    }
}

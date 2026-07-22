<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Gateway\Http
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Gateway\Http;

use Comfino\Api\Client;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Magento-specific Comfino API client factory.
 *
 * Wraps ApiClientFactory with Magento-specific concerns:
 * - Reads timeout / retry configuration from Magento admin config.
 * - Wires the config-driven timeouts into the MagentoHttpClientAdapter (TimeoutAwareClientInterface) so the very first
 *   request, as well as all subsequent retry attempts, use the operator-configured values.
 * - Converts all API exceptions into a consistent structure for logging.
 */
class ApiClient
{
    private const CHECKOUT_TRACK_ID_COOKIE = 'comfino_checkout_track_id';
    private const CHECKOUT_TRACK_ID_COOKIE_TTL = 900;
    private const CHECKOUT_TRACK_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

    private static ?Client $apiClient = null;
    private static ?Client $minimalTimeoutClient = null;

    /**
     * Returns (or creates) the shared Comfino API client instance.
     *
     * Configuration keys read from Magento admin:
     *   COMFINO_API_CONNECT_TIMEOUT - base connection timeout in seconds (default 1)
     *   COMFINO_API_TIMEOUT - base transfer timeout in seconds (default 3)
     *   COMFINO_API_CONNECT_NUM_ATTEMPTS - maximum retry attempts (default 3)
     *
     * The retry policy uses exponential backoff:
     *   attempt 1: configured values (e.g., 1 s / 3 s)
     *   attempt 2: 2 * base (e.g., 2 s / 6 s)
     *   attempt 3: 4 * base (e.g., 4 s / 12 s)
     *
     * Transfer timeout is silently normalized to at least 3 * connection timeout (ApiClientFactory requirement).
     */
    public static function getInstance(?bool $sandboxMode = null, ?string $apiKey = null): Client
    {
        if ($sandboxMode === null) {
            $sandboxMode = ConfigManager::isSandboxMode();
        }

        if ($apiKey === null) {
            $apiKey = ConfigManager::getApiKey() ?? '';
        }

        if (self::$apiClient === null) {
            $platformInfo = SdkBootstrap::getPlatformInfo();

            self::$apiClient = (new ApiClientFactory())->createClientFromPlatformInfo(
                $platformInfo,
                $apiKey,
                $sandboxMode,
                SdkBootstrap::getHttpClient(),
                SdkBootstrap::getRequestFactory(),
                SdkBootstrap::getStreamFactory(),
                (int) ConfigManager::getConfigurationValue('COMFINO_API_CONNECT_TIMEOUT', 1),
                (int) ConfigManager::getConfigurationValue('COMFINO_API_TIMEOUT', 3),
                (int) ConfigManager::getConfigurationValue('COMFINO_API_CONNECT_NUM_ATTEMPTS', 3),
            );

            self::$apiClient->setClientHostname($platformInfo->getDomain());

            // Override API base URL when explicitly configured (e.g., for dev/staging environments).
            if ($apiHost = ConfigManager::getApiHost()) {
                self::$apiClient->setCustomApiBaseUrl($apiHost);
            }
        } else {
            if ($apiHost = ConfigManager::getApiHost()) {
                self::$apiClient->setCustomApiBaseUrl($apiHost);
            }

            self::$apiClient->setApiKey($apiKey);

            // Re-apply current config timeouts to the adapter when the client is reused across requests.
            $httpClient = SdkBootstrap::getHttpClient();

            if ($httpClient instanceof TimeoutAwareClientInterface) {
                $connectionTimeout = (int) ConfigManager::getConfigurationValue('COMFINO_API_CONNECT_TIMEOUT', 1);
                $transferTimeout = (int) ConfigManager::getConfigurationValue('COMFINO_API_TIMEOUT', 3);

                $httpClient->updateTimeouts($connectionTimeout, $transferTimeout);
            }
        }

        if ($sandboxMode) {
            self::$apiClient->enableSandboxMode();
        } else {
            self::$apiClient->disableSandboxMode();
        }

        return self::$apiClient;
    }

    /**
     * Pins this instance's trackId to the checkout-scoped cookie value, so a checkout-page paywall render and the
     * later separate order-create request share the same trackId. Checkout-only: never call this from product-page
     * rendering, where a fresh trackId per page load is still correct behavior.
     */
    public static function pinCheckoutTrackId(): void
    {
        $client = self::getInstance();

        if (isset($_COOKIE[self::CHECKOUT_TRACK_ID_COOKIE]) &&
            preg_match(self::CHECKOUT_TRACK_ID_PATTERN, $_COOKIE[self::CHECKOUT_TRACK_ID_COOKIE]) === 1
        ) {
            $client->setTrackId($_COOKIE[self::CHECKOUT_TRACK_ID_COOKIE]);
        }

        $trackId = $client->getTrackId();

        if (!headers_sent()) {
            setcookie(self::CHECKOUT_TRACK_ID_COOKIE, $trackId, [
                'expires' => time() + self::CHECKOUT_TRACK_ID_COOKIE_TTL,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Returns (or creates) a dedicated API client instance with short timeouts and no retry escalation.
     *
     * Used exclusively by the outbound request queue drain so that queue operations never block longer than the
     * configured timeouts (default: 1 s connect / 2 s transfer, 1 attempt).
     */
    public static function getMinimalTimeoutInstance(): Client
    {
        $sandboxMode = ConfigManager::isSandboxMode();
        $apiKey = ConfigManager::getApiKey() ?? '';
        $connectTimeout = (int) ConfigManager::getConfigurationValue('COMFINO_API_QUEUE_CONNECT_TIMEOUT', 1);
        $transferTimeout = (int) ConfigManager::getConfigurationValue('COMFINO_API_QUEUE_TIMEOUT', 2);

        if (self::$minimalTimeoutClient === null) {
            $platformInfo = SdkBootstrap::getPlatformInfo();

            self::$minimalTimeoutClient = (new ApiClientFactory())->createClientFromPlatformInfo(
                $platformInfo,
                $apiKey,
                $sandboxMode,
                SdkBootstrap::getHttpClient(),
                SdkBootstrap::getRequestFactory(),
                SdkBootstrap::getStreamFactory(),
                $connectTimeout,
                $transferTimeout,
                1, // maxRetries: single attempt, no escalation
            );

            self::$minimalTimeoutClient->setClientHostname($platformInfo->getDomain());

            if ($apiHost = ConfigManager::getApiHost()) {
                self::$minimalTimeoutClient->setCustomApiBaseUrl($apiHost);
            }
        } else {
            if ($apiHost = ConfigManager::getApiHost()) {
                self::$minimalTimeoutClient->setCustomApiBaseUrl($apiHost);
            }

            self::$minimalTimeoutClient->setApiKey($apiKey);

            $httpClient = SdkBootstrap::getHttpClient();

            if ($httpClient instanceof TimeoutAwareClientInterface) {
                $httpClient->updateTimeouts($connectTimeout, $transferTimeout);
            }
        }

        if ($sandboxMode) {
            self::$minimalTimeoutClient->enableSandboxMode();
        } else {
            self::$minimalTimeoutClient->disableSandboxMode();
        }

        return self::$minimalTimeoutClient;
    }

    /**
     * Logs and reports an API exception.
     *
     * Extracts rich context from HttpErrorExceptionInterface (URL, bodies, timeout details) and
     * NetworkExceptionInterface (request target, body), then writes a structured entry to DebugLogger, and forwards
     * the error report to ErrorLogger.
     *
     * Connection timeouts (ConnectionTimeout) receive dedicated [API_TIMEOUT] debug events with attempt index and
     * final escalated timeout values.
     *
     * @param OperationContext $context What the plugin was doing when the error occurred.
     * @param ErrorCategory $category Technical classification of the error.
     * @param ErrorSeverity $severity Severity hint for the API-side classification pipeline.
     * @param Throwable $exception The caught API exception.
     */
    public static function processApiError(
        OperationContext $context,
        ErrorCategory $category,
        ErrorSeverity $severity,
        Throwable $exception
    ): void {
        $url = null;
        $requestBody = null;
        $responseBody = null;

        if ($exception instanceof HttpErrorExceptionInterface) {
            $url = $exception->getUrl();
            $requestBody = $exception->getRequestBody();
            $responseBody = $exception->getResponseBody();

            if ($exception instanceof ConnectionTimeout) {
                DebugLogger::logEvent(
                    $context->value,
                    [
                        'exception' => $exception->getPrevious() !== null
                            ? get_class($exception->getPrevious())
                            : '',
                        'code' => $exception->getPrevious() !== null
                            ? $exception->getPrevious()->getCode()
                            : 0,
                        'connect_attempt_idx' => $exception->getConnectAttemptIdx(),
                        'connection_timeout' => $exception->getConnectionTimeout(),
                        'transfer_timeout' => $exception->getTransferTimeout(),
                    ],
                    'API_TIMEOUT'
                );
            } elseif ($exception instanceof AccessDenied && $exception->getStatusCode() === 404) {
                /* 404 on the auth endpoint means the API key is invalid - not a transient error.
                   Skip the generic error report so we don't spam the error log for a misconfiguration; the debug
                   event below is enough. */
            }
        } elseif ($exception instanceof NetworkExceptionInterface) {
            $exception->getRequest()->getBody()->rewind();

            DebugLogger::logEvent($context->value . " [{$exception->getMessage()}]", null, 'API_NETWORK_ERROR');

            $url = $exception->getRequest()->getRequestTarget();
            $requestBody = $exception->getRequest()->getBody()->getContents();
        }

        DebugLogger::logEvent(
            $context->value,
            [
                'exception' => get_class($exception),
                'error_message' => $exception->getMessage(),
                'error_code' => $exception->getCode(),
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
                'error_trace' => $exception->getTraceAsString(),
            ],
            'API_ERROR'
        );

        ErrorLogger::sendError(
            $exception,
            $category,
            $severity,
            $context,
            (string) $exception->getCode(),
            $exception->getMessage(),
            $url !== null && $url !== '' ? $url : null,
            $requestBody !== null && $requestBody !== '' ? $requestBody : null,
            $responseBody !== null && $responseBody !== '' ? $responseBody : null,
            $exception->getTraceAsString()
        );
    }
}

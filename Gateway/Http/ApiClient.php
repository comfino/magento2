<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Gateway\Http
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Gateway\Http;

use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\Client;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\HttpErrorDetailsInterface;
use Comfino\Auth\ExceptionSanitizer;
use Comfino\Backend\Api\ApiErrorDetails;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Backend\Health\ApiHealthRecorder;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\Backend\Store\Psr6CircuitBreakerStore;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use InvalidArgumentException;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\CookieSizeLimitReachedException;
use Magento\Framework\Stdlib\Cookie\FailureToSendException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Throwable;

/**
 * Magento-specific ComfinoPay API client factory.
 *
 * Reads timeouts and retries from the admin config and passes them to the HTTP adapter, so the first request and every retry use
 * the operator-configured values; each client holds its own transport copy. Converts API exceptions into one structure for logging.
 */
class ApiClient
{
    /** Accepted format of a track ID read from a cookie or submitted by the frontend; anything else is discarded. */
    public const CHECKOUT_TRACK_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

    /** Name of the cookie that keeps the checkout track ID between the paywall render and the order creation. */
    private const CHECKOUT_TRACK_ID_COOKIE = 'comfino_checkout_track_id';

    /** Lifetime of the checkout track ID cookie in seconds, same as the SDK journey cookie. */
    private const CHECKOUT_TRACK_ID_COOKIE_TTL = 1800;

    /** Name of the SDK journey cookie; read only when paywall tracking consent is granted. */
    private const JOURNEY_TRACK_ID_COOKIE = 'comfino_journey_track_id';

    private const HEALTH_CACHE_SCOPE = 'comfino_api_health';

    private static ?Client $apiClient = null;
    private static ?CircuitBreaker $circuitBreaker = null;
    private static ?ApiHealthRecorder $healthRecorder = null;
    private static ?Client $minimalTimeoutClient = null;

    /**
     * Returns (or creates) the shared ComfinoPay API client.
     *
     * Timeouts and attempts come from the admin config; retries back off exponentially (1x, 2x, 4x the base timeouts).
     * The transfer timeout is raised to at least 3 * the connection timeout, as ApiClientFactory requires.
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
                ...ConfigManager::getApiTimeouts(),
                circuitBreaker: self::getCircuitBreaker(),
                requestObserver: self::getHealthRecorder(),
                tenantKey: ErrorLogger::getScope() ?: null
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
        }

        if ($sandboxMode) {
            self::$apiClient->enableSandboxMode();
        } else {
            self::$apiClient->disableSandboxMode();
        }

        return self::$apiClient;
    }

    /**
     * Pins this instance's trackId to the checkout cookie, so the checkout paywall render and the later order-create
     * request share it. Checkout-only: product pages must keep a fresh trackId per load.
     *
     * Precedence: the track ID submitted with the payment data, then the SDK journey cookie (only with statistics
     * consent enabled), then the checkout cookie, which may be missing or expired when the payment starts.
     *
     * @param string $submittedTrackId Track ID from the payment data, empty when there is none
     */
    public static function pinCheckoutTrackId(string $submittedTrackId = ''): void
    {
        $client = self::getInstance();
        $objectManager = ObjectManager::getInstance();

        /** @var CookieManagerInterface $cookieManager */
        $cookieManager = $objectManager->get(CookieManagerInterface::class);

        $journeyTrackId = ConfigManager::getConfigurationValue('COMFINO_PAYWALL_TRACKING_CONSENT')
            ? (string) $cookieManager->getCookie(self::JOURNEY_TRACK_ID_COOKIE, '')
            : '';

        if (preg_match(self::CHECKOUT_TRACK_ID_PATTERN, $submittedTrackId) === 1) {
            $client->setTrackId($submittedTrackId);
        } elseif (preg_match(self::CHECKOUT_TRACK_ID_PATTERN, $journeyTrackId) === 1) {
            $client->setTrackId($journeyTrackId);
        } else {
            $storedTrackId = (string) $cookieManager->getCookie(self::CHECKOUT_TRACK_ID_COOKIE, '');

            if (preg_match(self::CHECKOUT_TRACK_ID_PATTERN, $storedTrackId) === 1) {
                $client->setTrackId($storedTrackId);
            }
        }

        /** @var CookieMetadataFactory $cookieMetadataFactory */
        $cookieMetadataFactory = $objectManager->get(CookieMetadataFactory::class);

        /** @var HttpRequest $request */
        $request = $objectManager->get(HttpRequest::class);

        // Browsers drop a Secure cookie sent over plain HTTP, which would leave the order-create request without the checkout trackId.
        $cookieMetadata = $cookieMetadataFactory->createPublicCookieMetadata()
            ->setDuration(self::CHECKOUT_TRACK_ID_COOKIE_TTL)
            ->setPath('/')
            ->setSecure($request->isSecure())
            ->setHttpOnly(true)
            ->setSameSite('Lax');

        try {
            $cookieManager->setPublicCookie(self::CHECKOUT_TRACK_ID_COOKIE, $client->getTrackId(), $cookieMetadata);
        } catch (CookieSizeLimitReachedException | FailureToSendException | InputException $e) {
            /* Correlation is a diagnostic aid, never a checkout precondition: a cookie that cannot be sent (headers
               already flushed, size limit) leaves this render with its own trackId instead of failing the paywall. */
            DebugLogger::logEvent(
                'pinCheckoutTrackId: Failed to store the checkout track ID cookie.',
                ['exceptionMessage' => $e->getMessage()],
                'API_CLIENT'
            );
        }
    }

    /**
     * Releases the checkout trackId after the order was created with it, so a retry or the next order starts a new
     * journey; the API correlates paywall events by trackId, and a shared one mixes orders' events.
     */
    public static function releaseCheckoutTrackId(): void
    {
        $objectManager = ObjectManager::getInstance();

        /** @var CookieManagerInterface $cookieManager */
        $cookieManager = $objectManager->get(CookieManagerInterface::class);

        /** @var CookieMetadataFactory $cookieMetadataFactory */
        $cookieMetadataFactory = $objectManager->get(CookieMetadataFactory::class);

        try {
            $cookieManager->deleteCookie(
                self::CHECKOUT_TRACK_ID_COOKIE,
                $cookieMetadataFactory->createCookieMetadata()->setPath('/')
            );
        } catch (FailureToSendException | InputException $e) {
            // Same as in pinCheckoutTrackId(): correlation is a diagnostic aid, never a checkout precondition.
            DebugLogger::logEvent(
                'releaseCheckoutTrackId: Failed to delete the checkout track ID cookie.',
                ['exceptionMessage' => $e->getMessage()],
                'API_CLIENT'
            );
        }
    }

    /**
     * Returns (or creates) a client with short timeouts and no retry escalation, for the outbound queue drain.
     *
     * Uses the credentials and API host of the ambient store; for a queued operation of a specific store use {@see getMinimalTimeoutInstanceForStore()}.
     */
    public static function getMinimalTimeoutInstance(): Client
    {
        return self::configureMinimalTimeoutClient(ConfigManager::isSandboxMode(), ConfigManager::getApiKey() ?? '', ConfigManager::getApiHost());
    }

    /**
     * Minimal-timeout client with the sandbox flag, API key and API host of the given store.
     *
     * The queue drain runs in the crontab area where the ambient scope is the default store, so a queued operation of another
     * store must not read the ambient settings.
     *
     * @throws InvalidArgumentException When the store has no API key, so the request cannot authenticate.
     */
    public static function getMinimalTimeoutInstanceForStore(int $storeId): Client
    {
        if (($apiKey = ConfigManager::getApiKeyForStore($storeId) ?? '') === '') {
            throw new InvalidArgumentException(sprintf('No ComfinoPay API key is configured for store %d.', $storeId));
        }

        return self::configureMinimalTimeoutClient(ConfigManager::isSandboxModeForStore($storeId), $apiKey, ConfigManager::getApiHostForStore($storeId));
    }

    /**
     * Circuit breaker of the storefront and admin API client, kept in the Magento cache so all PHP workers share it. After
     * CircuitBreaker::DEFAULT_FAILURE_THRESHOLD consecutive failures for a store and API host, calls fail at once for
     * DEFAULT_OPEN_DURATION_SECONDS, then one probe call decides whether it closes again. Null without the cache.
     */
    public static function getCircuitBreaker(): ?CircuitBreaker
    {
        try {
            return self::$circuitBreaker ??= new CircuitBreaker(new Psr6CircuitBreakerStore(CacheManager::getPool()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Records the last successful and failed API call and the consecutive failures per store, for the diagnostics. Uses
     * one cache view for every store, so the admin can read any store's health. Null without the cache.
     */
    public static function getHealthRecorder(): ?ApiHealthRecorder
    {
        try {
            return self::$healthRecorder ??= new ApiHealthRecorder(CacheManager::forScope(self::HEALTH_CACHE_SCOPE));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Logs and reports an API exception.
     *
     * Validation and authorization failures stay local, idempotent failures are not reported, transient ones (timeout, network, 5xx, 429)
     * are reported as a Warning, the rest at the caller's severity. Response bodies are summarized and capped first.
     *
     * @param OperationContext $context What the plugin was doing when the error occurred.
     * @param ErrorCategory $category Technical classification of the error.
     * @param ErrorSeverity $severity Severity for a failure that is neither transient nor idempotent.
     * @param Throwable $exception The caught API exception.
     */
    public static function processApiError(
        OperationContext $context,
        ErrorCategory $category,
        ErrorSeverity $severity,
        Throwable $exception
    ): void {
        $details = ApiErrorDetails::fromThrowable($exception);

        if ($details->isTimeout) {
            DebugLogger::logEvent(
                $context->value,
                [
                    'exception' => $exception->getPrevious() !== null ? get_class($exception->getPrevious()) : '',
                    'code' => $exception->getPrevious() !== null ? $exception->getPrevious()->getCode() : 0,
                    'connect_attempt_idx' => $details->connectAttemptIdx,
                    'connection_timeout' => $details->connectionTimeout,
                    'transfer_timeout' => $details->transferTimeout,
                ],
                'API_TIMEOUT'
            );
        } elseif ($details->isNetworkError) {
            DebugLogger::logEvent(
                $context->value . ' [' . ExceptionSanitizer::redactText($exception->getMessage()) . ']',
                null,
                'API_NETWORK_ERROR'
            );
        }

        DebugLogger::logEvent(
            $context->value,
            [
                'exception' => get_class($exception),
                'error_message' => ExceptionSanitizer::redactText($exception->getMessage()),
                'error_code' => $exception->getCode(),
                'status_code' => $details->statusCode,
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
                'error_trace' => ExceptionSanitizer::redactText($exception->getTraceAsString()),
            ],
            'API_ERROR'
        );

        if ($exception instanceof HttpErrorDetailsInterface && $exception->isIdempotentFailure()) {
            return;
        }

        ErrorLogger::sendError(
            $exception,
            $category,
            SdkErrorLogger::resolveSeverity($exception, $severity),
            $context,
            (string) $exception->getCode(),
            $exception->getMessage(),
            $details->url,
            $details->requestBody,
            $details->responseBody,
            $exception->getTraceAsString()
        );
    }

    private static function configureMinimalTimeoutClient(bool $sandboxMode, string $apiKey, ?string $apiHost): Client
    {
        if (self::$minimalTimeoutClient === null) {
            [$connectTimeout, $transferTimeout] = ConfigManager::getQueueApiTimeouts();
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
        } else {
            self::$minimalTimeoutClient->setApiKey($apiKey);
        }

        // Set on every call to reset an override the current store doesn't use: the client is shared between stores.
        self::$minimalTimeoutClient->setCustomApiBaseUrl($apiHost ?: null);

        if ($sandboxMode) {
            self::$minimalTimeoutClient->enableSandboxMode();
        } else {
            self::$minimalTimeoutClient->disableSandboxMode();
        }

        return self::$minimalTimeoutClient;
    }
}

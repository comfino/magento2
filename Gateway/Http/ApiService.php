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

use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Store\Psr6TokenBucketStore;
use Comfino\Backend\Webhook\ClientIpResolver;
use Comfino\Backend\Webhook\Endpoint\CacheInvalidate;
use Comfino\Backend\Webhook\Endpoint\Configuration;
use Comfino\Backend\Webhook\Endpoint\StatusNotification;
use Comfino\Backend\Webhook\IpWhitelist;
use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Backend\Webhook\WebhookManager;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Http\Psr17Factory;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\StatusAdapter;
use Comfino\ComfinoGateway\Model\Webhook\FailedSignatureCounter;
use Comfino\ComfinoGateway\Model\Webhook\WebhookRateLimiter;
use Comfino\Shop\Order\StatusManager;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Central REST endpoint manager for module API: status notification webhook, remote configuration and cache invalidation.
 */
class ApiService
{
    /** Enough of the received signature to correlate a delivery, not enough to replay it. */
    private const SIGNATURE_LOG_PREFIX_LENGTH = 8;
    private const RESPONSE_BODY_LOG_LENGTH = 512;

    private static ?WebhookManager $endpointManager = null;
    private static bool $initialized = false;

    public static function init(
        string $notificationUrl,
        string $configUrl,
        string $cacheInvalidateUrl,
        string $magentoVersion,
        string $pluginVersion,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ): void {
        if (self::$initialized) {
            return;
        }

        $endpointManager = self::getEndpointManager($magentoVersion, $pluginVersion);

        $endpointManager->registerEndpoint(
            new StatusNotification(
                'transactionStatus',
                $notificationUrl,
                StatusManager::getInstance(new StatusAdapter($orderRepository, $searchCriteriaBuilder)),
                ConfigManager::getForbiddenStatuses(),
                ConfigManager::getIgnoredStatuses()
            )
        );

        $endpointManager->registerEndpoint(
            new Configuration(
                'configuration',
                $configUrl,
                ConfigManager::getInstance(),
                DebugLogger::getLoggerInstance(),
                'Magento',
                $magentoVersion,
                $pluginVersion,
                Data::BUILD_TS,
                ConfigManager::getEnvironmentInfo(['database_version'])['database_version'],
                200,
                null, // $shopExtraVariables
                static function (): ?array {
                    return \Magento\Framework\App\ObjectManager::getInstance()
                        ->get(\Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter::class)
                        ->getReportArray();
                }, // $shopEnvironmentReportProvider
                ConfigManager::isDebugLogRemoteReadEnabled()
            )
        );

        $endpointManager->registerEndpoint(
            new CacheInvalidate(
                'cacheInvalidate',
                $cacheInvalidateUrl,
                CacheManager::getPool(),
                /* The cache scope SettingsManager stores this store view's product types, creditors and remote flags
                   in, so a tag invalidation (e.g. "remote_flags") reaches exactly those entries. */
                ErrorLogger::getScope()
            )
        );

        self::$initialized = true;
    }

    /**
     * Lazy-initialize ApiService from Magento ObjectManager if not yet initialized. Safe to call multiple times.
     */
    public static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }

        $om = ObjectManager::getInstance();

        /** @var Data $helper */
        $helper = $om->get(Data::class);

        /** @var UrlInterface $urlBuilder */
        $urlBuilder = $om->get(UrlInterface::class);

        /** @var OrderRepository $orderRepository */
        $orderRepository = $om->get(OrderRepository::class);

        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $om->get(SearchCriteriaBuilder::class);

        self::init(
            rtrim($urlBuilder->getUrl('comfino/transactionstatus'), '/'),
            rtrim($urlBuilder->getUrl('comfino/configuration'), '/'),
            rtrim($urlBuilder->getUrl('comfino/cacheinvalidate'), '/'),
            $helper->getShopVersion(),
            $helper->getModuleVersion(),
            $orderRepository,
            $searchCriteriaBuilder
        );
    }

    /**
     * Process a named endpoint request. Returns PSR-7 ResponseInterface from WebhookManager.
     */
    public static function processRequest(string $endpointName): ResponseInterface
    {
        self::ensureInitialized();

        if (ConfigManager::isDebugMode()) {
            $request = self::$endpointManager->getServerRequest();

            // Headers and body are not logged: they carry the signature and the order and customer data.
            DebugLogger::logEvent(
                'processRequest',
                [
                    '$endpointName' => $endpointName,
                    'METHOD' => $request->getMethod(),
                    'BODY_SIZE' => $request->getBody()->getSize(),
                ],
                'REST_API_REQUEST'
            );
        }

        $response = self::$endpointManager->processRequest($endpointName);

        if (ConfigManager::isDebugMode() && $response->getStatusCode() !== 200) {
            DebugLogger::logEvent(
                'processRequest',
                [
                    '$endpointName' => $endpointName,
                    'VERIFICATION' => self::$endpointManager->getLastVerificationOutcome()->value,
                    'RECEIVED-CR-SIGNATURE-PREFIX' => substr(
                        (string) self::$endpointManager->getReceivedCrSignature(),
                        0,
                        self::SIGNATURE_LOG_PREFIX_LENGTH
                    ),
                    'STATUS' => $response->getStatusCode(),
                    'BODY' => substr((string) $response->getBody(), 0, self::RESPONSE_BODY_LOG_LENGTH),
                ],
                'REST_API_RESPONSE'
            );
        }

        return $response;
    }

    /**
     * API keys a webhook signature is checked against for the addressed store: the production key, and the sandbox key
     * only in sandbox mode. Empty or too short keys are left out, so a shop without a key rejects every webhook.
     *
     * @return string[]
     */
    public static function getWebhookApiKeys(): array
    {
        $apiKeys = [ConfigManager::getConfigurationValue('COMFINO_API_KEY')];

        if (ConfigManager::isSandboxMode()) {
            array_unshift($apiKeys, ConfigManager::getConfigurationValue('COMFINO_SANDBOX_API_KEY'));
        }

        return array_values(array_filter(
            array_map(static fn (mixed $apiKey): string => is_string($apiKey) ? trim($apiKey) : '', $apiKeys),
            static fn (string $apiKey): bool => strlen($apiKey) >= TenantWebhookContext::MIN_API_KEY_LENGTH
        ));
    }

    private static function getEndpointManager(string $magentoVersion, string $pluginVersion): WebhookManager
    {
        if (self::$endpointManager === null) {
            $psr17Factory = new Psr17Factory();
            $tenantKey = ErrorLogger::getScope() ?: null;

            // One resolver for the rate limiter and the allow-list, so both agree on who called.
            $ipResolver = new ClientIpResolver();

            self::$endpointManager = new WebhookManager(
                'Magento',
                $magentoVersion,
                $pluginVersion,
                new StaticApiKeyResolver(self::getWebhookApiKeys(), $tenantKey),
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                new JsonSerializer(),
                rateLimiter: self::createRateLimiter(),
                ipWhitelist: IpWhitelist::forComfino(
                    includeSandbox: true,
                    enforce: ConfigManager::isWebhookIpAllowlistEnforced(),
                    logger: self::getPsrLogger()
                ),
                ipResolver: $ipResolver,
                verificationObserver: self::getFailedSignatureCounter()
            );
        }

        return self::$endpointManager;
    }

    /**
     * Counter of webhook requests whose signature did not verify (diagnostics), or null when the cache is unavailable.
     */
    public static function getFailedSignatureCounter(): ?FailedSignatureCounter
    {
        try {
            return new FailedSignatureCounter(CacheManager::getPool());
        } catch (Throwable) {
            return null;
        }
    }

    private static function createRateLimiter(): ?WebhookRateLimiter
    {
        try {
            return new WebhookRateLimiter(new TokenBucketRateLimiter(
                WebhookRateLimiter::CAPACITY,
                WebhookRateLimiter::REFILL_PER_SECOND,
                new Psr6TokenBucketStore(CacheManager::getPool())
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ComfinoPay log channel: allow-list mismatches are written there whether or not debug mode is on.
     */
    private static function getPsrLogger(): ?LoggerInterface
    {
        try {
            $logger = ObjectManager::getInstance()->get('ComfinoDebugLogger');

            return $logger instanceof LoggerInterface ? $logger : null;
        } catch (Throwable) {
            return null;
        }
    }
}

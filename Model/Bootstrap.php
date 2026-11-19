<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model;

use Closure;
use Comfino\Api\ClientInterface as ApiClientInterface;
use Comfino\Backend\Factory\OutboundRequestQueueFactory;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Queue\DeadLetterReporter;
use Comfino\ComfinoGateway\Model\Queue\MagentoRetryQueueStorage;
use Comfino\ComfinoGateway\Model\Queue\PauseReporter;
use Comfino\ComfinoGateway\Model\Queue\QueueState;
use Comfino\ComfinoGateway\Model\Queue\StoreAwareCancelOrderHandler;
use Comfino\ComfinoGateway\Model\Queue\TenantKey;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use InvalidArgumentException;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires Magento-provided PSR services into the ComfinoPay SDK singletons; called once early in the request from BootstrapObserver.
 */
class Bootstrap
{
    /** Queued requests older than this (7 days) are dropped instead of sent. */
    public const QUEUE_MAX_AGE_SECONDS = 604800;

    private static ?self $bootstrapInstance = null;
    private static ?OutboundRequestQueue $outboundQueue = null;
    private static ?OutboundRequestQueueProcessor $queueProcessor = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly LoggerInterface $logger,
        private readonly PlatformInfoInterface $platformInfo,
        private readonly LanguageProviderInterface $languageProvider,
        private readonly ThemeFamilyRules $themeFamilyRules,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
        private readonly ?LoggerInterface $errorLogger = null,
        private readonly ?QueueState $queueState = null
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function init(): void
    {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        SdkBootstrap::init(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->cachePool,
            $this->logger,
            $this->platformInfo,
            $this->languageProvider,
            $this->themeFamilyRules,
            (string) $this->storeManager->getStore()->getId()
        );

        if ($this->errorLogger !== null) {
            SdkErrorLogger::init($this->errorLogger);
            SdkErrorLogger::init($this->errorLogger, ErrorLogger::getScope());
        }

        self::$bootstrapInstance = $this;

        if ($this->tokenRefreshAllowedHere()) {
            ConfigManager::refreshErrorLoggingTokenIfNeeded();
        }

        $initialized = true;
    }

    public static function getOutboundQueue(): OutboundRequestQueue
    {
        if (self::$outboundQueue === null) {
            self::buildQueue();
        }

        return self::$outboundQueue;
    }

    public static function getQueueProcessor(): OutboundRequestQueueProcessor
    {
        if (self::$queueProcessor === null) {
            self::buildQueue();
        }

        return self::$queueProcessor;
    }

    private static function buildQueue(): void
    {
        $instance = self::$bootstrapInstance;

        self::$outboundQueue = (new OutboundRequestQueueFactory())->create(
            new MagentoRetryQueueStorage($instance->resourceConnection),
            ApiClient::getMinimalTimeoutInstance(),
            logger: $instance->logger,
            deadLetterReporter: new DeadLetterReporter($instance->queueState),
            maxAttempts: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_MAX_ATTEMPTS', 10),
            pauseReporter: $instance->queueState !== null ? new PauseReporter($instance->queueState) : null,
            tenantClientFactory: self::tenantClientFactory()
        );

        /* Replace the SDK's cancel_order handler with the multistore-safe one. The factory-registered default binds a
           single client resolved from the ambient config scope, which in the crontab area is always the default store -
           see StoreAwareCancelOrderHandler for the failure that causes. */
        self::$outboundQueue->registerHandler(CancelOrderHandler::OPERATION_TYPE, new StoreAwareCancelOrderHandler());

        /* No tenant key here: the one cron job drains every store's partition (the queue cycles them). Requests older
           than QUEUE_MAX_AGE_SECONDS are dead-lettered instead of sent, and older rows are deleted. */
        self::$queueProcessor = new OutboundRequestQueueProcessor(
            self::$outboundQueue,
            defaultBatchSize: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_BATCH_SIZE', 20),
            cooldownSeconds: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_COOLDOWN', 300),
            maxAgeSeconds: self::QUEUE_MAX_AGE_SECONDS
        );
    }

    /**
     * Client factory for delivering a queued error report with the credentials of its originating store. A store without
     * an API key throws, which keeps the report queued instead of sending it with another store's key.
     *
     * @return Closure(?string): ApiClientInterface
     */
    private static function tenantClientFactory(): Closure
    {
        return static function (?string $tenantKey): ApiClientInterface {
            if (($storeId = TenantKey::toStoreId($tenantKey)) === null) {
                return ApiClient::getMinimalTimeoutInstance();
            }

            return ApiClient::getMinimalTimeoutInstanceForStore($storeId);
        };
    }

    /**
     * Whether the current area may claim an error-logging access token. The claim writes config and flushes the `config`
     * cache, so it is left to the admin and the daily cron ({@see \Comfino\ComfinoGateway\Cron\RefreshShopEnvironment});
     * an unresolvable area counts as unsafe.
     */
    private function tokenRefreshAllowedHere(): bool
    {
        try {
            return in_array($this->appState->getAreaCode(), [Area::AREA_ADMINHTML, Area::AREA_CRONTAB, Area::AREA_GLOBAL], true);
        } catch (LocalizedException) {
            return false;
        }
    }
}

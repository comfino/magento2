<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model;

use Comfino\Backend\Factory\OutboundRequestQueueFactory;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Queue\DeadLetterReporter;
use Comfino\ComfinoGateway\Model\Queue\MagentoRetryQueueStorage;
use Comfino\ComfinoGateway\Model\Queue\StoreAwareCancelOrderHandler;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Plugin bootstrap - wires Magento-provided PSR services into the Comfino SDK singletons.
 *
 * Injected by Magento DI; called once early in the request lifecycle from BootstrapObserver (event:
 * controller_front_init_before). All SDK singletons (CacheManager, DebugLogger, ErrorLogger, ApiClient)
 * are usable after this call.
 */
class Bootstrap
{
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
        private readonly State $appState
    ) {
    }

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
            deadLetterReporter: new DeadLetterReporter(),
            maxAttempts: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_MAX_ATTEMPTS', 10)
        );

        /* Replace the SDK's cancel_order handler with the multistore-safe one. The factory-registered default binds a
           single client resolved from the ambient config scope, which in the crontab area is always the default store -
           see StoreAwareCancelOrderHandler for the failure that causes. */
        self::$outboundQueue->registerHandler(
            CancelOrderHandler::OPERATION_TYPE,
            new StoreAwareCancelOrderHandler()
        );

        self::$queueProcessor = new OutboundRequestQueueProcessor(
            self::$outboundQueue,
            defaultBatchSize: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_BATCH_SIZE', 20),
            cooldownSeconds: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_COOLDOWN', 300)
        );
    }

    /**
     * Whether the current area may claim a CETS access token.
     *
     * A successful claim writes two config values, and StorageAdapter::save() flushes the whole `config` cache type
     * afterwards - not something to trigger from a shopper-facing request. The claim is therefore left to the admin
     * panel and to the daily cron ({@see \Comfino\ComfinoGateway\Cron\RefreshShopEnvironment}), which between them
     * keep the token fresh well inside its lifetime. An area that cannot be resolved is treated as unsafe.
     */
    private function tokenRefreshAllowedHere(): bool
    {
        try {
            return in_array(
                $this->appState->getAreaCode(),
                [Area::AREA_ADMINHTML, Area::AREA_CRONTAB, Area::AREA_GLOBAL],
                true
            );
        } catch (LocalizedException) {
            return false;
        }
    }
}

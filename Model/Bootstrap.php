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
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Queue\DeadLetterReporter;
use Comfino\ComfinoGateway\Model\Queue\MagentoRetryQueueStorage;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Magento\Framework\App\ResourceConnection;
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
        private readonly StoreManagerInterface $storeManager
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

        ConfigManager::refreshErrorLoggingTokenIfNeeded();

        self::$bootstrapInstance = $this;

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

        self::$queueProcessor = new OutboundRequestQueueProcessor(
            self::$outboundQueue,
            defaultBatchSize: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_BATCH_SIZE', 20),
            cooldownSeconds: (int) ConfigManager::getConfigurationValue('COMFINO_RETRY_QUEUE_COOLDOWN', 300)
        );
    }
}

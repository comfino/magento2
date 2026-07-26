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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Factory\WebhookManagerFactory;
use Comfino\Backend\Webhook\Endpoint\CacheInvalidate;
use Comfino\Backend\Webhook\Endpoint\Configuration;
use Comfino\Backend\Webhook\Endpoint\StatusNotification;
use Comfino\Backend\Webhook\WebhookManager;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\StatusAdapter;
use Comfino\ComfinoGateway\Http\Psr17Factory;
use Comfino\Shop\Order\StatusManager;
use Psr\Http\Message\ResponseInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\OrderRepository;

/**
 * Central REST endpoint manager for module API.
 *
 * Handles:
 * - StatusNotification (webhook from Comfino -> order status update).
 * - Configuration (remote config management by Comfino admin panel).
 * - CacheInvalidate (cache clearing endpoint).
 */
class ApiService
{
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
                } // $shopEnvironmentReportProvider
            )
        );

        $endpointManager->registerEndpoint(
            new CacheInvalidate(
                'cacheInvalidate',
                $cacheInvalidateUrl,
                CacheManager::getPool()
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

            DebugLogger::logEvent(
                'processRequest',
                [
                    '$endpointName' => $endpointName,
                    'METHOD' => $request->getMethod(),
                    'PARAMS' => $request->getQueryParams(),
                    'HEADERS' => $request->getHeaders(),
                    'BODY' => $request->getBody()->getContents(),
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
                    'RECEIVED-CR-SIGNATURE' => self::$endpointManager->getReceivedCrSignature(),
                    'CALCULATED-CR-SIGNATURE' => self::$endpointManager->getCalculatedCrSignature(),
                    'HEADERS' => $response->getHeaders(),
                    'STATUS' => $response->getStatusCode(),
                    'BODY' => $response->getBody()->getContents(),
                ],
                'REST_API_RESPONSE'
            );
        }

        return $response;
    }

    private static function getEndpointManager(string $magentoVersion, string $pluginVersion): WebhookManager
    {
        if (self::$endpointManager === null) {
            $psr17Factory = new Psr17Factory();

            self::$endpointManager = (new WebhookManagerFactory())->createWebhookManager(
                'Magento',
                $magentoVersion,
                $pluginVersion,
                [
                    ConfigManager::getConfigurationValue('COMFINO_API_KEY'),
                    ConfigManager::getConfigurationValue('COMFINO_SANDBOX_API_KEY'),
                ],
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                new JsonSerializer()
            );
        }

        return self::$endpointManager;
    }
}

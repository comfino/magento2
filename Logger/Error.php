<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Logger
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Logger;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Throwable;

/**
 * Error logging facade for Magento.
 *
 * Reports errors through the SDK error logger with a structured classification: an ErrorCategory (technical nature),
 * an ErrorSeverity, and an OperationContext (what the plugin was doing). The API-side pipeline reads these typed fields
 * instead of parsing a free-form message prefix.
 *
 * @see SdkErrorLogger
 */
class Error
{
    private static ?SdkErrorLogger $errorLogger = null;

    public static function init(): void
    {
        static $initialized = false;

        if (!$initialized) {
            self::getLoggerInstance()->initHandlers();

            $initialized = true;
        }
    }

    public static function getLoggerInstance(): SdkErrorLogger
    {
        if (self::$errorLogger === null) {
            $basePath = defined('BP') ? BP : ObjectManager::getInstance()->get(DirectoryList::class)->getRoot();
            self::$errorLogger = SdkErrorLogger::getInstance(
                ApiClient::getInstance(),
                $basePath . '/var/log/comfino_errors.log',
                self::getShopDomain(),
                'Magento',
                'Comfino_ComfinoGateway',
                ConfigManager::getEnvironmentInfo(),
                ConfigManager::isRetryQueueEnabled() ? Bootstrap::getOutboundQueue() : null
            );
        }

        return self::$errorLogger;
    }

    /**
     * Reports an error through the SDK error logger.
     *
     * The caller location is captured automatically by the SDK, so no free-form prefix is needed — instead, the caller
     * supplies the structured classification. AuthorizationError and ResponseValidationError are silently dropped:
     *  - Validation errors are already collected at the API side (response with status code 400).
     *  - Authorization errors caused by an empty or wrong API key (response with status code 401).
     */
    public static function sendError(
        Throwable $exception,
        ErrorCategory $category,
        ErrorSeverity $severity,
        OperationContext $context,
        string $errorCode,
        string $errorMessage,
        ?string $apiRequestUrl = null,
        ?string $apiRequest = null,
        ?string $apiResponse = null,
        ?string $stackTrace = null
    ): void {
        if ($exception instanceof ResponseValidationError || $exception instanceof AuthorizationError) {
            return;
        }

        self::getLoggerInstance()->sendError(
            $category,
            $severity,
            $context,
            $errorCode,
            $errorMessage,
            $apiRequestUrl,
            $apiRequest,
            $apiResponse,
            $stackTrace ?? $exception->getTraceAsString(),
            /* contextDepth: 1 skips this facade's static frame so the SDK records the real caller. */
            1
        );
    }

    public static function getErrorLog(int $numLines): string
    {
        return self::getLoggerInstance()->getErrorLog($numLines);
    }

    public static function clearLogs(): void
    {
        self::getLoggerInstance()->clearLogs();
    }

    private static function getShopDomain(): string
    {
        /** @var Data $helper */
        $helper = ObjectManager::getInstance()->get(Data::class);

        return $helper->getShopDomain();
    }
}

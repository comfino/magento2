<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Logger
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Logger;

use Comfino\Auth\ExceptionSanitizer;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Queue\TenantKey;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Error logging facade for Magento.
 *
 * Reports errors through the SDK error logger with a structured classification (ErrorCategory, ErrorSeverity,
 * OperationContext) instead of a free-form message prefix.
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
                ConfigManager::isRetryQueueEnabled() ? Bootstrap::getOutboundQueue() : null,
                scope: self::getScope()
            );

            /* API request and response bodies and the stack trace go to the local log only in debug mode; otherwise the
               entry carries a report ID that matches it to the report sent to ComfinoPay. */
            self::$errorLogger->setVerboseLocalLog(ConfigManager::isDebugMode());
        }

        return self::$errorLogger;
    }

    /**
     * Reports an error through the SDK error logger with a structured classification.
     *
     * Outcomes that are not platform defects (`ErrorLogger::isReportable()`: invalid request, wrong or empty API key) go
     * to the local error log only. A ResponseValidationError is reported: it means a success response broke the contract.
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
        if (!SdkErrorLogger::isReportable($exception)) {
            self::getLoggerInstance()->logError(
                "[{$category->value}][{$context->value}]",
                sprintf('%s: %s', get_class($exception), ExceptionSanitizer::redactText($errorMessage))
            );

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
            // contextDepth: 1 skips this facade's static frame so the SDK records the real caller.
            1
        );
    }

    /**
     * Reports a warning that has no exception behind it (e.g. a summary of a recurring checkout problem).
     */
    public static function sendWarning(
        ErrorCategory $category,
        OperationContext $context,
        string $errorCode,
        string $errorMessage
    ): void {
        self::getLoggerInstance()->sendError(
            $category,
            ErrorSeverity::Warning,
            $context,
            $errorCode,
            $errorMessage,
            contextDepth: 1
        );
    }

    /**
     * Scope of the error logger: the current store's tenant key, so a queued report is delivered with the credentials
     * of the store it was raised in. Empty when the store cannot be resolved (the single, unscoped logger).
     */
    public static function getScope(): string
    {
        try {
            /** @var StoreManagerInterface $storeManager */
            $storeManager = ObjectManager::getInstance()->get(StoreManagerInterface::class);
            $storeId = (int) $storeManager->getStore()->getId();

            return $storeId > 0 ? TenantKey::forStore($storeId) : '';
        } catch (Throwable) {
            return '';
        }
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

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

use Comfino\Backend\Log\CookieServiceModeChecker;
use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;

/**
 * Debug logging facade for Magento.
 *
 * Delegates to the SDK DebugLogger, which auto-captures the caller context via debug_backtrace(). Because this facade
 * adds one static frame, every call forwards contextDepth: 1, so the captured caller is the real call site, not this
 * facade method.
 *
 * @see SdkDebugLogger
 */
class Debug
{
    private static ?SdkDebugLogger $debugLogger = null;
    private static ?CookieServiceModeChecker $cookieChecker = null;

    public static function getLoggerInstance(): SdkDebugLogger
    {
        if (self::$debugLogger === null) {
            $basePath = defined('BP') ? BP : ObjectManager::getInstance()->get(DirectoryList::class)->getRoot();
            self::$debugLogger = SdkDebugLogger::getInstance($basePath . '/var/log/comfino_debug.log');
        }

        return self::$debugLogger;
    }

    private static function getCookieChecker(): CookieServiceModeChecker
    {
        if (self::$cookieChecker === null) {
            self::$cookieChecker = new CookieServiceModeChecker();
        }

        return self::$cookieChecker;
    }

    /**
     * Logs a debug event with automatic caller-context capture.
     *
     * @param array<string, mixed>|null $parameters
     */
    public static function logEvent(string $eventMessage, ?array $parameters = null, ?string $eventType = null): void
    {
        if (ConfigManager::isServiceMode() && !self::getCookieChecker()->isServiceMode()) {
            return;
        }

        if (ConfigManager::isDebugMode()) {
            // contextDepth: 1 skips this facade's static frame so the SDK records the real caller.
            self::getLoggerInstance()->logEvent($eventMessage, $parameters, $eventType, 1);
        }
    }

    public static function clearLogs(): void
    {
        self::getLoggerInstance()->clearLogs();
    }
}

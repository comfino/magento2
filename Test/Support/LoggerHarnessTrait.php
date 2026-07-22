<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Support;

use Comfino\Api\ClientInterface;
use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use ReflectionProperty;

/**
 * Injects in-memory loggers into the Debug and Error facades.
 *
 * Without this, the facades lazily build real SDK loggers that touch the filesystem and the Magento ObjectManager.
 * Intended for use inside a PHPUnit TestCase (relies on its mock builders).
 */
trait LoggerHarnessTrait
{
    /**
     * Installs a mock SDK debug logger and a real (but API-stubbed) SDK error logger.
     *
     * The error logger is given a mocked ClientInterface so the logger never falls back to writing the local log file.
     */
    protected function installLoggerHarness(): void
    {
        $debugLogger = $this->getMockBuilder(SdkDebugLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['logEvent'])
            ->getMock();

        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, $debugLogger);

        $apiClient = $this->createMock(ClientInterface::class);

        SdkErrorLogger::reset();

        $errorLogger = SdkErrorLogger::getInstance(
            $apiClient,
            sys_get_temp_dir() . '/comfino_test_errors.log',
            'example.com',
            'Magento',
            'Comfino_ComfinoGateway',
            []
        );

        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, $errorLogger);
    }

    protected function resetLoggerHarness(): void
    {
        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, null);
        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, null);
        SdkErrorLogger::reset();
    }
}
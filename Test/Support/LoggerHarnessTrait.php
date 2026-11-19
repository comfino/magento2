<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
use PHPUnit\Framework\MockObject\Exception;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Injects in-memory loggers into the Debug and Error facades, which otherwise build real SDK loggers that touch the
 * filesystem and the Magento ObjectManager. For use inside a PHPUnit TestCase.
 */
trait LoggerHarnessTrait
{
    /**
     * Installs a mock SDK debug logger and an API-stubbed SDK error logger, so it never writes the local log file.
     *
     * @throws Exception
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

        // Local error lines go to a null channel, so tests never share content through the fallback log file.
        SdkErrorLogger::init(new NullLogger());

        if (is_file($errorLogFile = sys_get_temp_dir() . '/comfino_test_errors.log')) {
            unlink($errorLogFile);
        }

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

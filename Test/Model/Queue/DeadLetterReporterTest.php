<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Queue;

use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\ComfinoGateway\Logger\Debug;
use Comfino\ComfinoGateway\Model\Queue\DeadLetterReporter;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class DeadLetterReporterTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(Debug::class, 'debugLogger'))->setValue(null, null);
        (new ReflectionProperty(Debug::class, 'cookieChecker'))->setValue(null, null);

        ConfigManagerHarness::reset();
        SdkDebugLogger::reset();

        parent::tearDown();
    }

    private function injectSdkLogger(): SdkDebugLogger&MockObject
    {
        $logger = $this->getMockBuilder(SdkDebugLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['logEvent', 'clearLogs'])
            ->getMock();

        (new ReflectionProperty(Debug::class, 'debugLogger'))->setValue(null, $logger);

        return $logger;
    }

    private function makeRequest(int $attempts = 3): QueuedRequest
    {
        return new QueuedRequest(42, 'cancel_order', ['order_id' => '99'], $attempts, 1748000000, 'prior error');
    }

    public function testReportLogsWhenDebugModeIsOn(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())
            ->method('logEvent')
            ->with($this->stringContains('cancel_order'), $this->anything(), 'QUEUE_DEAD_LETTER', 1);

        (new DeadLetterReporter())->report($this->makeRequest(), new RuntimeException('connection failed'));
    }

    public function testReportSilentWhenDebugModeIsOff(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => false, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->never())->method('logEvent');

        (new DeadLetterReporter())->report($this->makeRequest(), new RuntimeException('connection failed'));
    }

    public function testReportIncludesAttemptCountInMessage(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())
            ->method('logEvent')
            ->with($this->stringContains('3 attempt'), $this->anything(), 'QUEUE_DEAD_LETTER', 1);

        (new DeadLetterReporter())->report($this->makeRequest(3), new RuntimeException('db gone'));
    }

    public function testReportIncludesErrorClassInMessage(): void
    {
        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);

        $logger = $this->injectSdkLogger();
        $logger->expects($this->once())
            ->method('logEvent')
            ->with($this->stringContains('RuntimeException'), $this->anything(), 'QUEUE_DEAD_LETTER', 1);

        (new DeadLetterReporter())->report($this->makeRequest(), new RuntimeException('some error'));
    }
}

<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Magento\Framework\Controller\Result {
    if (!class_exists(JsonFactory::class, false)) {
        class JsonFactory
        {
            public function create(): Json
            {
                throw new \LogicException('stub');
            }
        }
    }
}

namespace Comfino\Tests\Controller\Adminhtml\Log {

    use Comfino\ComfinoGateway\Controller\Adminhtml\Log\Clear;
    use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
    use Comfino\Tests\Support\LoggerHarnessTrait;
    use Magento\Backend\App\Action\Context;
    use Magento\Framework\App\RequestInterface;
    use Magento\Framework\Controller\Result\Json;
    use Magento\Framework\Controller\Result\JsonFactory;
    use Magento\Framework\Exception\NotFoundException;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use RuntimeException;

    /**
     * Clear::execute dispatches on the 'type' param: known types clear the matching logger and succeed, unknown types and
     * clearLogs() failures return {success:false}. Loggers are stubbed, so no real log file is touched.
     */
    final class ClearTest extends TestCase
    {
        use LoggerHarnessTrait;

        protected function setUp(): void
        {
            parent::setUp();

            $this->installLoggerHarness();

            /* installLoggerHarness only stubs the debug logger's logEvent; Clear calls clearLogs(), so swap in a debug
               logger whose clearLogs() is stubbed to avoid touching the uninitialized real logger state. */
            $debugLogger = $this->getMockBuilder(\Comfino\Backend\Log\DebugLogger::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['clearLogs'])
                ->getMock();
            $debugLogger->method('clearLogs')->willReturn(0);

            (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, $debugLogger);
        }

        protected function tearDown(): void
        {
            $this->resetLoggerHarness();

            parent::tearDown();
        }

        /** @var array<string, mixed>|null */
        private ?array $captured = null;

        private function makeController(?string $type): Clear
        {
            $request = $this->createMock(RequestInterface::class);
            $request->method('getParam')->with('type')->willReturn($type);

            $context = $this->createMock(Context::class);
            $context->method('getRequest')->willReturn($request);

            $json = $this->createMock(Json::class);
            $json->method('setData')->willReturnCallback(
                function ($data) use ($json): Json {
                    $this->captured = $data;

                    return $json;
                }
            );

            $jsonFactory = $this->createMock(JsonFactory::class);
            $jsonFactory->method('create')->willReturn($json);

            return new Clear($context, $jsonFactory);
        }

        /**
         * @throws NotFoundException
         */
        public function testClearsErrorLog(): void
        {
            $this->makeController('error')->execute();

            $this->assertSame(['success' => true], $this->captured);
        }

        /**
         * @throws NotFoundException
         */
        public function testClearsDebugLog(): void
        {
            $this->makeController('debug')->execute();

            $this->assertSame(['success' => true], $this->captured);
        }

        /**
         * @throws NotFoundException
         */
        public function testRejectsUnknownLogType(): void
        {
            $this->makeController('something-else')->execute();

            $this->assertSame(['success' => false, 'message' => 'Unknown log type.'], $this->captured);
        }

        /**
         * @throws NotFoundException
         */
        public function testRejectsNullLogType(): void
        {
            $this->makeController(null)->execute();

            $this->assertSame(['success' => false, 'message' => 'Unknown log type.'], $this->captured);
        }

        /**
         * @throws NotFoundException
         */
        public function testCatchesClearLogsException(): void
        {
            /* Swap the debug facade's logger for one whose clearLogs() throws, to drive the catch branch. */
            $throwingLogger = $this->getMockBuilder(\Comfino\Backend\Log\DebugLogger::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['clearLogs'])
                ->getMock();
            $throwingLogger->method('clearLogs')->willThrowException(new RuntimeException('disk error'));

            (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, $throwingLogger);

            $this->makeController('debug')->execute();

            $this->assertSame(['success' => false, 'message' => 'disk error'], $this->captured);
        }
    }
}

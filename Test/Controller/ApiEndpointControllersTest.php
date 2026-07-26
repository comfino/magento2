<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Magento\Framework\Controller\Result {
    if (!class_exists(RawFactory::class, false)) {
        class RawFactory
        {
            public function create(): Raw
            {
                throw new \LogicException('stub');
            }
        }
    }
}

namespace Comfino\Tests\Controller {

    use Comfino\Api\Serializer\Json as JsonSerializer;
    use Comfino\Backend\Webhook\WebhookManager;
    use Comfino\ComfinoGateway\Controller\CacheInvalidate\Index as CacheInvalidateIndex;
    use Comfino\ComfinoGateway\Controller\Configuration\Index as ConfigurationIndex;
    use Comfino\ComfinoGateway\Controller\TransactionStatus\Index as TransactionStatusIndex;
    use Comfino\ComfinoGateway\Gateway\Http\ApiService;
    use Comfino\ComfinoGateway\Http\Psr17Factory;
    use Comfino\Tests\Support\ConfigManagerHarness;
    use Comfino\Tests\Support\LoggerHarnessTrait;
    use Magento\Framework\App\CsrfAwareActionInterface;
    use Magento\Framework\App\RequestInterface;
    use Magento\Framework\Controller\Result\Raw;
    use Magento\Framework\Controller\Result\RawFactory;
    use Magento\Framework\Controller\ResultInterface;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * The three webhook endpoint controllers (cacheInvalidate / configuration / transactionStatus) share one body:
     * ErrorLogger::init() then prepareResult(ApiService::processRequest(<name>)). ApiService is seeded with a real
     * WebhookManager (no registered endpoints, no CR-Signature header) so processRequest resolves to a deterministic
     * 4xx PSR-7 response without any network or ObjectManager access; the controller wraps it into a Raw result.
     * The CSRF hooks are asserted directly.
     */
    final class ApiEndpointControllersTest extends TestCase
    {
        use LoggerHarnessTrait;

        protected function setUp(): void
        {
            parent::setUp();

            ConfigManagerHarness::install(['COMFINO_DEBUG' => false, 'COMFINO_RETRY_QUEUE_ENABLED' => false]);

            $this->installLoggerHarness();
            $this->seedApiService();
        }

        protected function tearDown(): void
        {
            (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, false);
            (new ReflectionProperty(ApiService::class, 'endpointManager'))->setValue(null, null);

            $this->resetLoggerHarness();

            ConfigManagerHarness::reset();

            parent::tearDown();
        }

        /**
         * Publishes a real WebhookManager as the already-initialized ApiService endpoint manager.
         */
        private function seedApiService(): void
        {
            $factory = new Psr17Factory();

            $manager = new WebhookManager(
                'Magento',
                '2.4.8',
                '4.0.0',
                ['production-key', 'sandbox-key'],
                $factory,
                $factory,
                $factory,
                $factory,
                new JsonSerializer()
            );

            (new ReflectionProperty(ApiService::class, 'endpointManager'))->setValue(null, $manager);
            (new ReflectionProperty(ApiService::class, 'initialized'))->setValue(null, true);
        }

        private function makeFactory(): RawFactory
        {
            $raw = $this->createMock(Raw::class);
            $raw->method('setHttpResponseCode')->willReturnSelf();
            $raw->method('setHeader')->willReturnSelf();
            $raw->method('setContents')->willReturnSelf();

            $factory = $this->createMock(RawFactory::class);
            $factory->method('create')->willReturn($raw);

            return $factory;
        }

        public function testCacheInvalidateExecuteReturnsResult(): void
        {
            $controller = new CacheInvalidateIndex($this->makeFactory());

            $this->assertInstanceOf(ResultInterface::class, $controller->execute());
            $this->assertCsrfHooks($controller);
        }

        public function testConfigurationExecuteReturnsResult(): void
        {
            $controller = new ConfigurationIndex($this->makeFactory());

            $this->assertInstanceOf(ResultInterface::class, $controller->execute());
            $this->assertCsrfHooks($controller);
        }

        public function testTransactionStatusExecuteReturnsResult(): void
        {
            $controller = new TransactionStatusIndex($this->makeFactory());

            $this->assertInstanceOf(ResultInterface::class, $controller->execute());
            $this->assertCsrfHooks($controller);
        }

        /**
         * Every endpoint opts out of Magento's form-key CSRF check: no exception object, validation forced to true.
         */
        private function assertCsrfHooks(CsrfAwareActionInterface $controller): void
        {
            $request = $this->createMock(RequestInterface::class);

            $this->assertNull($controller->createCsrfValidationException($request));
            $this->assertTrue($controller->validateForCsrf($request));
        }
    }
}
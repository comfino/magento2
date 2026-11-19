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

namespace Comfino\Tests\Gateway\Http;

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * One reporting policy for API errors: validation outcomes stay local, idempotent failures are not reported, transient
 * failures are Warnings, everything else keeps the caller's severity; response bodies are summarized.
 */
final class ProcessApiErrorTest extends TestCase
{
    private ClientInterface&MockObject $apiClient;
    /** @var ShopPluginError[] */
    private array $reports = [];
    private string|false $devEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devEnv = getenv('COMFINO_DEV_ENV');

        putenv('COMFINO_DEV_ENV');

        ConfigManagerHarness::install(['COMFINO_DEBUG' => false, 'COMFINO_SERVICE_MODE' => false]);

        SdkErrorLogger::reset();

        $this->apiClient = $this->createMock(ClientInterface::class);
        $this->apiClient->method('sendLoggedError')->willReturnCallback(function (ShopPluginError $error): bool {
            $this->reports[] = $error;

            return true;
        });

        $errorLogger = SdkErrorLogger::getInstance(
            $this->apiClient,
            sys_get_temp_dir() . '/comfino_test_errors.log',
            'example.com',
            'Magento',
            dirname(__DIR__, 3),
            []
        );

        SdkErrorLogger::init(new NullLogger());

        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, $errorLogger);
        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(
            null,
            $this->getMockBuilder(SdkDebugLogger::class)->disableOriginalConstructor()->onlyMethods(['logEvent'])->getMock()
        );
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, null);
        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, null);

        SdkErrorLogger::reset();
        ConfigManagerHarness::reset();

        putenv($this->devEnv === false ? 'COMFINO_DEV_ENV' : 'COMFINO_DEV_ENV=' . $this->devEnv);

        parent::tearDown();
    }

    public function testATransientFailureIsReportedAsAWarning(): void
    {
        $this->process(new ServiceUnavailable('Service unavailable', 503, null, 'https://api.example/v1/orders', '{}', 'down'), ErrorSeverity::Critical);

        $this->assertCount(1, $this->reports);
        $this->assertSame(ErrorSeverity::Warning, $this->reports[0]->severity);
    }

    public function testAnUnexpectedFailureKeepsTheCallersSeverity(): void
    {
        $this->process(new RuntimeException('Unexpected'), ErrorSeverity::Critical);

        $this->assertCount(1, $this->reports);
        $this->assertSame(ErrorSeverity::Critical, $this->reports[0]->severity);
    }

    public function testAnIdempotentFailureIsNotReported(): void
    {
        $notFound = (new NotFound('Order not found', 404, null, 'https://api.example/v1/orders/1/cancel'))->setErrorDetails(false, true);

        $this->process($notFound, ErrorSeverity::Error);

        $this->assertSame([], $this->reports);
    }

    public function testAnotherNotFoundIsReported(): void
    {
        $this->process(new NotFound('Not found', 404, null, 'https://api.example/v1/widget-key'), ErrorSeverity::Error);

        $this->assertCount(1, $this->reports);
        $this->assertSame(ErrorSeverity::Error, $this->reports[0]->severity);
    }

    public function testARejectedRequestIsNotReported(): void
    {
        $this->process(new RequestValidationError('Invalid request data', 400), ErrorSeverity::Critical);

        $this->assertSame([], $this->reports);
    }

    public function testAnHtmlErrorPageIsSummarizedInTheReport(): void
    {
        $page = '<!DOCTYPE html><html><head><style>body{color:red}</style><script>var x=1;</script></head>'
            . '<body><h1>Internal Server Error</h1><p>Symfony debug &amp; trace</p></body></html>';

        $this->process(new ServiceUnavailable('Server error', 500, null, 'https://api.example/v1/orders', '{}', $page), ErrorSeverity::Critical);

        $this->assertCount(1, $this->reports);
        $this->assertStringStartsWith('[HTML response] ', (string) $this->reports[0]->apiResponse);
        $this->assertStringContainsString('Internal Server Error', (string) $this->reports[0]->apiResponse);
        $this->assertStringNotContainsString('color:red', (string) $this->reports[0]->apiResponse);
        $this->assertStringNotContainsString('<h1>', (string) $this->reports[0]->apiResponse);
    }

    private function process(Throwable $exception, ErrorSeverity $severity): void
    {
        ApiClient::processApiError(OperationContext::OrderCreation, ErrorCategory::ApiError, $severity, $exception);
    }
}

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

namespace Comfino\Tests\Logger;

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Backend\Log\ErrorLogger as SdkErrorLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Throwable;

/**
 * The error facade reports platform defects and keeps outcomes that are not defects
 * (a rejected request, a wrong API key) in the local error log only.
 */
final class ErrorTest extends TestCase
{
    private ClientInterface&MockObject $apiClient;
    private LoggerInterface&MockObject $localLog;
    private string|false $devEnv;

    protected function setUp(): void
    {
        parent::setUp();

        // With the development environment on, the SDK logs locally instead of sending.
        $this->devEnv = getenv('COMFINO_DEV_ENV');

        putenv('COMFINO_DEV_ENV');

        SdkErrorLogger::reset();

        $this->apiClient = $this->createMock(ClientInterface::class);
        $this->localLog = $this->createMock(LoggerInterface::class);

        $errorLogger = SdkErrorLogger::getInstance(
            $this->apiClient,
            sys_get_temp_dir() . '/comfino_test_errors.log',
            'example.com',
            'Magento',
            dirname(__DIR__, 2),
            []
        );

        SdkErrorLogger::init($this->localLog);

        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, $errorLogger);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ErrorLogger::class, 'errorLogger'))->setValue(null, null);

        SdkErrorLogger::reset();

        putenv($this->devEnv === false ? 'COMFINO_DEV_ENV' : 'COMFINO_DEV_ENV=' . $this->devEnv);

        parent::tearDown();
    }

    public function testARejectedRequestIsLoggedLocallyOnly(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');
        $this->localLog->expects($this->once())->method('error')
            ->with($this->stringContains('RequestValidationError'));

        $this->send(new RequestValidationError('Invalid request data: customer jan@example.com', 400));
    }

    public function testAWrongApiKeyIsLoggedLocallyOnly(): void
    {
        $this->apiClient->expects($this->never())->method('sendLoggedError');
        $this->localLog->expects($this->once())->method('error');

        $this->send(new AuthorizationError('unauthorized', 401));
    }

    public function testTheLocalLineIsRedacted(): void
    {
        $this->localLog->expects($this->once())->method('error')
            ->with($this->logicalNot($this->stringContains('jan@example.com')));

        $this->send(new RequestValidationError('rejected', 400), 'Invalid customer jan@example.com');
    }

    /**
     * A malformed API response is a contract violation, not a validation outcome: it is reported.
     */
    public function testAMalformedApiResponseIsReported(): void
    {
        $this->apiClient->expects($this->once())->method('sendLoggedError');

        $this->send(new ResponseValidationError('Invalid response data', 200));
    }

    public function testSendErrorWithContextMethodNoLongerExists(): void
    {
        // sendErrorWithContext() was removed; sendError() is now the single structured entry point.
        $this->assertFalse(method_exists(ErrorLogger::class, 'sendErrorWithContext'));
    }

    private function send(Throwable $exception, ?string $message = null): void
    {
        ErrorLogger::sendError(
            $exception,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            OperationContext::ApiCommunication,
            (string) $exception->getCode(),
            $message ?? $exception->getMessage()
        );
    }
}

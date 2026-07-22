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

namespace Comfino\Tests\Logger;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use PHPUnit\Framework\TestCase;

/**
 * Covers the early-return guard in the error facade: validation and authorization errors are intentionally dropped
 * before any logging happens (they are already collected API-side). These branches are the only ones reachable in a
 * pure unit test — the remaining paths delegate to the SDK's final ErrorLogger, which needs a live ApiClient and the
 * Magento ObjectManager, so they are exercised by integration tests instead.
 */
final class ErrorTest extends TestCase
{
    public function testSendErrorDropsResponseValidationErrorWithoutLogging(): void
    {
        $exception = new ResponseValidationError('invalid response', 400);

        ErrorLogger::sendError(
            $exception,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            OperationContext::ApiCommunication,
            '400',
            'invalid response'
        );

        /* Reaching this point proves the guard returned before touching the (uninitialized) SDK logger, which would
           otherwise throw while resolving its dependencies. */
        $this->expectNotToPerformAssertions();
    }

    public function testSendErrorDropsAuthorizationErrorWithoutLogging(): void
    {
        $exception = new AuthorizationError('unauthorized', 401);

        ErrorLogger::sendError(
            $exception,
            ErrorCategory::ApiError,
            ErrorSeverity::Warning,
            OperationContext::ApiCommunication,
            '401',
            'unauthorized'
        );

        $this->expectNotToPerformAssertions();
    }

    public function testSendErrorWithContextMethodNoLongerExists(): void
    {
        /* sendErrorWithContext() was removed; sendError() is now the single structured entry point. */
        $this->assertFalse(method_exists(ErrorLogger::class, 'sendErrorWithContext'));
    }
}
<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Queue;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\Auth\ExceptionSanitizer;
use Throwable;

/**
 * Makes permanently failed queue requests visible: records them for the admin diagnostics, reports a dead-lettered
 * cancellation to ComfinoPay as a Warning (without the payload), and logs the details to the debug log. A dead-lettered
 * error report is not reported again, so an unreachable API cannot feed the queue with reports about itself.
 */
class DeadLetterReporter implements DeadLetterReporterInterface
{
    public function __construct(private readonly ?QueueState $queueState = null)
    {
    }

    public function report(QueuedRequest $request, Throwable $error): void
    {
        $this->queueState?->recordDeadLetter($request->operationType);

        if ($request->operationType === CancelOrderHandler::OPERATION_TYPE) {
            try {
                ErrorLogger::sendWarning(
                    ErrorCategory::ApiError,
                    OperationContext::OrderCancellation,
                    'QUEUE_DEAD_LETTER',
                    sprintf(
                        'Queued order cancellation dropped after %d attempt(s): %s',
                        $request->attempts,
                        get_class($error)
                    )
                );
            } catch (Throwable) {
                // Reporting is best-effort.
            }
        }

        DebugLogger::logEvent(
            sprintf(
                'Operation "%s" dropped after %d attempt(s): [%s] %s',
                $request->operationType,
                $request->attempts,
                get_class($error),
                ExceptionSanitizer::redactText($error->getMessage())
            ),
            [
                'dedup_key' => $request->dedupKey(),
                'payload' => $request->payload,
                'created_at' => $request->createdAt,
                'last_error' => $request->lastError,
            ],
            'QUEUE_DEAD_LETTER'
        );
    }
}

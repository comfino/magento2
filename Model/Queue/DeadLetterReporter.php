<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Queue;

use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Throwable;

/**
 * Logs permanently-failed queue entries to the Comfino debug log.
 */
class DeadLetterReporter implements DeadLetterReporterInterface
{
    public function report(QueuedRequest $request, Throwable $error): void
    {
        DebugLogger::logEvent(
            sprintf(
                'Operation "%s" dropped after %d attempt(s): [%s] %s',
                $request->operationType,
                $request->attempts,
                get_class($error),
                $error->getMessage()
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

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

use Comfino\Auth\ExceptionSanitizer;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\TenantPauseReporterInterface;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Throwable;

/**
 * Records a paused store (the API rejected its key, or its requests kept failing) for the admin notice and diagnostics,
 * and logs it. The queue retries the store's requests after the pause; nothing is dropped.
 */
class PauseReporter implements TenantPauseReporterInterface
{
    public function __construct(private readonly QueueState $queueState)
    {
    }

    public function reportPaused(?string $tenantKey, QueuedRequest $request, Throwable $error, string $reason): void
    {
        try {
            $this->queueState->recordPause($tenantKey, $reason);

            DebugLogger::logEvent(
                'Queue paused for a store.',
                [
                    'tenantKey' => $tenantKey,
                    'reason' => $reason,
                    'operation' => $request->operationType,
                    'error' => get_class($error) . ': ' . ExceptionSanitizer::redactText($error->getMessage()),
                ],
                'REQUEST_QUEUE'
            );
        } catch (Throwable) {
            // Must not throw: the drain calls this while holding a batch.
        }
    }
}

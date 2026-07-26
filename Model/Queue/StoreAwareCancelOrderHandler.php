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

use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use InvalidArgumentException;

/**
 * Multistore-safe replacement for the SDK's {@see CancelOrderHandler}.
 *
 * The SDK handler is constructed with one API client and calls it directly, which makes the request inherit whatever
 * config scope is ambient at drain time. In the crontab area that is always the default store — so a cancellation
 * enqueued by a website that overrides `payment/comfino/api_key` (or `sandbox`) would be resent with the *default*
 * store's credentials. The API answers 401, the queue classifies that as a permanent error, and the cancellation is
 * dropped: silent data loss, and only on the deferred path, which is exactly the path the queue exists to provide.
 *
 * This handler instead resolves the credentials of the store recorded in the payload and passes them explicitly to the
 * client. No store emulation and no ambient-state mutation is involved, so the drain stays cheap and cannot leak a
 * half-restored environment into whatever runs after it.
 *
 * Payloads without a `storeId` (rows enqueued by module versions before this fix) fall back to ambient resolution —
 * i.e. the previous behavior — rather than being dropped.
 */
class StoreAwareCancelOrderHandler implements RetryableOperationHandlerInterface
{
    /**
     * @param array<string, scalar> $payload
     */
    public function execute(array $payload): void
    {
        $orderId = (string) ($payload['orderId'] ?? '');

        if ($orderId === '') {
            throw new InvalidArgumentException('The cancel_order payload requires a non-empty "orderId".');
        }

        $storeId = isset($payload['storeId']) ? (int) $payload['storeId'] : 0;

        if ($storeId <= 0) {
            // Legacy row without store identity - preserve the pre-fix behavior.
            ApiClient::getMinimalTimeoutInstance()->cancelOrder($orderId);

            return;
        }

        $sandboxMode = ConfigManager::isSandboxModeForStore($storeId);
        $apiKey = ConfigManager::getApiKeyForStore($storeId) ?? '';

        if ($apiKey === '') {
            /* No key configured for this store, so the request cannot authenticate. Throwing keeps the row queued:
               ApiTransientErrorClassifier treats non-HTTP throwables as Retry, so it is retried until maxAttempts and
               then dead-lettered (and reported). That is the wanted outcome here - an operator who is midway through
               configuring the store still gets the cancellation delivered, and a store that is never configured
               surfaces in the dead-letter log instead of failing silently. */
            DebugLogger::logEvent(
                sprintf(
                    'cancel_order: No API key configured for store %d - keeping order %s queued.',
                    $storeId,
                    $orderId
                ),
                ['storeId' => $storeId, 'orderId' => $orderId],
                'REQUEST_QUEUE'
            );

            throw new InvalidArgumentException(
                sprintf('No Comfino API key is configured for store %d.', $storeId)
            );
        }

        ApiClient::getMinimalTimeoutInstance($sandboxMode, $apiKey)->cancelOrder($orderId);
    }
}

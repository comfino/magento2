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

use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\TenantAwareRetryableOperationHandlerInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Order\CancellationGuard;
use InvalidArgumentException;
use Magento\Framework\App\ObjectManager;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

/**
 * Multistore-safe replacement for the SDK's {@see CancelOrderHandler}.
 *
 * The SDK handler uses the config scope ambient at drain time (always the default store in cron), so a cancellation from a
 * website with its own API key would fail authentication and be dropped. This handler resolves the credentials of the
 * request's store (tenant key "store:<id>", or payload `storeId` for 4.0.x rows) and passes them explicitly.
 *
 * Rows naming no store (enqueued before 4.0.1) fall back to ambient resolution rather than being dropped.
 */
class StoreAwareCancelOrderHandler implements TenantAwareRetryableOperationHandlerInterface
{
    /**
     * @param CancellationGuard|null $cancellationGuard Delivery-time check; null resolves it from the object manager
     */
    public function __construct(private ?CancellationGuard $cancellationGuard = null)
    {
    }

    /**
     * @param array<string, scalar> $payload
     *
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function execute(array $payload): void
    {
        $this->executeForTenant($payload, null);
    }

    /**
     * @param array<string, scalar> $payload
     *
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     * @throws Throwable
     */
    public function executeForTenant(array $payload, ?string $tenantKey): void
    {
        $orderId = (string) ($payload['orderId'] ?? '');

        if ($orderId === '') {
            throw new InvalidArgumentException('The cancel_order payload requires a non-empty "orderId".');
        }

        if (($guard = $this->cancellationGuard()?->getQueuedCancelSuppressionReason($orderId)) !== null) {
            /* The order reached a terminal ComfinoPay status after the cancel was queued (e.g. ComfinoPay rejected the
               application meanwhile), so ComfinoPay already treats it as closed. Completing as a no-op. */
            DebugLogger::logEvent(
                sprintf('cancel_order: Order %s already closed at ComfinoPay - not sent.', $orderId),
                ['orderId' => $orderId, 'guard' => $guard],
                'REQUEST_QUEUE'
            );

            return;
        }

        $storeId = TenantKey::toStoreId($tenantKey) ?? (isset($payload['storeId']) ? (int) $payload['storeId'] : 0);

        if ($storeId <= 0) {
            // Legacy row without store identity - preserve the pre-fix behavior.
            ApiClient::getMinimalTimeoutInstance()->cancelOrder($orderId);

            return;
        }

        try {
            $client = ApiClient::getMinimalTimeoutInstanceForStore($storeId);
        } catch (InvalidArgumentException $e) {
            /* No key for this store, so the request cannot authenticate. Rethrowing keeps the row queued (retried until
               maxAttempts, then dead-lettered and reported), so a store still being configured gets the cancellation
               delivered and a never-configured one surfaces in the dead-letter log. */
            DebugLogger::logEvent(
                sprintf(
                    'cancel_order: No API key configured for store %d - keeping order %s queued.',
                    $storeId,
                    $orderId
                ),
                ['storeId' => $storeId, 'orderId' => $orderId],
                'REQUEST_QUEUE'
            );

            throw $e;
        }

        $client->cancelOrder($orderId);
    }

    private function cancellationGuard(): ?CancellationGuard
    {
        try {
            return $this->cancellationGuard ??= ObjectManager::getInstance()->get(CancellationGuard::class);
        } catch (\RuntimeException) {
            // No object manager outside the application (unit tests): deliver without the check, as before.
            return null;
        }
    }
}

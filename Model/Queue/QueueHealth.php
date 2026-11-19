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

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Tells whether the outbound request queue is being drained. Only the scheduled task (every 5 minutes) sends queued requests
 * and Magento does not warn when cron stops, so requests overdue by more than OVERDUE_SECONDS signal a missing or stuck cron.
 */
class QueueHealth
{
    public const OVERDUE_SECONDS = 1800;

    private ?int $overdueCount = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly QueueState $queueState,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Number of overdue requests, computed once per request; 0 when the queue is disabled or cannot be read.
     */
    public function getOverdueCount(): int
    {
        if ($this->overdueCount === null) {
            try {
                $this->overdueCount = ConfigManager::isRetryQueueEnabled()
                    ? $this->createStorage()->countOverdue(self::OVERDUE_SECONDS)
                    : 0;
            } catch (Throwable) {
                $this->overdueCount = 0;
            }
        }

        return $this->overdueCount;
    }

    /**
     * Number of requests waiting in the queue, overdue or not; 0 when the queue cannot be read.
     */
    public function getPendingCount(): int
    {
        try {
            return $this->createStorage()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Stores whose queued requests are paused (the API rejected the store's key, or its requests kept failing), as
     * display name => pause reason.
     *
     * @return array<string, string>
     */
    public function getPausedStores(): array
    {
        $pausedStores = [];

        foreach ($this->queueState->getPausedTenants() as $tenantKey => $pause) {
            $pausedStores[$this->getStoreLabel((string) $tenantKey)] = $pause['reason'];
        }

        return $pausedStores;
    }

    /**
     * Requests dropped after their last attempt within the last 7 days, per operation type.
     *
     * @return array<string, int>
     */
    public function getDeadLetterCounts(): array
    {
        return $this->queueState->getDeadLetterCounts();
    }

    protected function createStorage(): MagentoRetryQueueStorage
    {
        return new MagentoRetryQueueStorage($this->resourceConnection);
    }

    private function getStoreLabel(string $tenantKey): string
    {
        if (($storeId = TenantKey::toStoreId($tenantKey)) === null) {
            return (string) __('Default store view');
        }

        try {
            return sprintf('%s (ID %d)', $this->storeManager->getStore($storeId)->getName(), $storeId);
        } catch (Throwable) {
            return sprintf('ID %d', $storeId);
        }
    }
}

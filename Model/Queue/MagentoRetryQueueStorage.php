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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\PurgeableRetryQueueStorageInterface;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Magento durable storage for the outbound request queue (`comfino_request_queue`); the auto-increment `request_id` keeps delivery FIFO.
 *
 * peekBatch() claims rows with `locked_at = NOW()`; locks older than 120 s are reclaimed. The table is install-wide while the
 * API key is per store, so `tenant_key` is honored in every read path (NULL for single-store installs and pre-existing rows).
 * `available_at` is a Unix timestamp integer, so SQL compares it directly with no session time-zone or DST shift.
 */
class MagentoRetryQueueStorage implements RetryQueueStorageInterface, PurgeableRetryQueueStorageInterface
{
    private const TABLE = 'comfino_request_queue';
    private const LOCK_TTL_SECONDS = 120;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly JsonSerializer $serializer = new JsonSerializer()
    ) {}

    public function enqueue(QueuedRequest $request): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        // INSERT IGNORE skips silently when a row with the same dedup_key already exists.
        $connection->query(
            "INSERT IGNORE INTO {$table} (operation_type, payload, attempts, dedup_key, last_error, created_at, locked_at, tenant_key, available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $request->operationType,
                $this->serializer->serialize($request->payload),
                $request->attempts,
                $request->dedupKey(),
                $request->lastError,
                date('Y-m-d H:i:s', $request->createdAt),
                null,
                $request->tenantKey,
                $request->availableAt,
            ]
        );
    }

    /**
     * @return QueuedRequest[]
     */
    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        // Select oldest claimable rows by FIFO key.
        $select = $connection->select()
            ->from($table, ['request_id'])
            ->order('request_id ASC')
            ->limit($limit);

        $this->applyClaimableFilters($select, $tenantKey, $dueAt);

        $ids = $connection->fetchCol($select);

        if (empty($ids)) {
            return [];
        }

        // Atomically claim the selected rows.
        $connection->update($table, ['locked_at' => date('Y-m-d H:i:s')], ['request_id IN (?)' => $ids]);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('request_id IN (?)', $ids)
                ->order('request_id ASC')
        );

        return array_map([$this, 'rowToRequest'], $rows);
    }

    public function update(QueuedRequest $request): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update(
            $table,
            [
                'attempts' => $request->attempts,
                'last_error' => $request->lastError,
                /* The next-attempt time has to be written here or the SDK's per-item backoff has no effect: the processor
                   records a failure with withAttemptFailure()/deferredTo(), which return a request carrying a future
                   availableAt, and this is the only call that persists it. */
                'available_at' => $request->availableAt,
                'locked_at' => null, // Release lock so next drain can pick it up.
            ],
            ['request_id = ?' => $request->id]
        );
    }

    public function remove(QueuedRequest $request): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->delete($table, ['request_id = ?' => $request->id]);
    }

    public function count(?string $tenantKey = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()->from($table, ['COUNT(*)']);

        if ($tenantKey !== null) {
            $select->where('tenant_key = ?', $tenantKey);
        }

        return (int) $connection->fetchOne($select);
    }

    public function purge(?int $createdBefore = null, ?string $tenantKey = null, ?string $operationType = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $where = [];

        if ($createdBefore !== null) {
            $where['created_at < ?'] = date('Y-m-d H:i:s', $createdBefore);
        }

        if ($tenantKey !== null) {
            $where['tenant_key = ?'] = $tenantKey;
        }

        if ($operationType !== null) {
            $where['operation_type = ?'] = $operationType;
        }

        return $connection->delete($this->resourceConnection->getTableName(self::TABLE), $where);
    }

    /**
     * Counts requests due more than $overdueSeconds ago (since their retry time, or enqueue time if never tried) and not
     * held by a live drain; non-zero means the scheduled drain is not running.
     */
    public function countOverdue(int $overdueSeconds, ?int $now = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $cutoff = ($now ?? time()) - $overdueSeconds;

        $select = $connection->select()->from($table, ['COUNT(*)']);
        $select->where('locked_at IS NULL OR locked_at < ?', date('Y-m-d H:i:s', time() - self::LOCK_TTL_SECONDS));
        $select->where(sprintf(
            '(available_at > 0 AND available_at < %d) OR (available_at = 0 AND created_at < %s)',
            $cutoff,
            $connection->quote(date('Y-m-d H:i:s', $cutoff))
        ));

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return list<string|null>
     */
    public function pendingTenantKeys(?int $dueAt = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        /* Ordered by each tenant's oldest claimable row, so the longest-waiting tenant drains first. Same lock/due filters
           as peekBatch(): a tenant with nothing claimable must not be returned, or it wastes a round-robin slot. */
        $select = $connection->select()
            ->from($table, ['tenant_key' => 'tenant_key', 'oldest_request_id' => new Expression('MIN(request_id)')])
            ->group('tenant_key')
            ->order('oldest_request_id ASC');

        $this->applyClaimableFilters($select, null, $dueAt);

        $keys = [];

        foreach ($connection->fetchAll($select) as $row) {
            $key = $row['tenant_key'] ?? null;
            /* '' and NULL are the same tenant as far as the SDK is concerned -- QueuedRequest::fromArray() normalizes an
               empty string to null -- so they must not be reported as two. */
            $keys[] = ($key === null || $key === '') ? null : (string) $key;
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * Restricts a select to rows a drain may claim now: not locked (or stale-locked), due, and optionally of one tenant.
     * A null $tenantKey means "every tenant" and must not filter on `tenant_key IS NULL`.
     *
     * @param \Magento\Framework\DB\Select $select Select to constrain in place
     */
    private function applyClaimableFilters($select, ?string $tenantKey, ?int $dueAt): void
    {
        $select->where('locked_at IS NULL OR locked_at < ?', date('Y-m-d H:i:s', time() - self::LOCK_TTL_SECONDS));

        if ($dueAt !== null) {
            $select->where('available_at <= ?', $dueAt);
        }

        if ($tenantKey !== null) {
            $select->where('tenant_key = ?', $tenantKey);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToRequest(array $row): QueuedRequest
    {
        $payload = $this->serializer->unserialize((string) ($row['payload'] ?? '{}'));

        return QueuedRequest::fromArray([
            'id' => (int) $row['request_id'],
            'operationType' => (string) $row['operation_type'],
            'payload' => is_array($payload) ? $payload : [],
            'attempts' => (int) $row['attempts'],
            'createdAt' => (int) strtotime((string) ($row['created_at'] ?? 'now')),
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            /* fromArray() maps an empty string to null, so a row written before tenant_key existed and a row written
               with no tenant both hydrate to the same null tenant. */
            'tenantKey' => isset($row['tenant_key']) ? (string) $row['tenant_key'] : null,
            'availableAt' => (int) ($row['available_at'] ?? 0),
        ]);
    }
}

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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Magento-specific durable storage for the outbound request queue.
 *
 * Uses the `comfino_request_queue` table (declared in db_schema.xml). Row insertion order (auto-increment PK
 * `request_id`) guarantees FIFO delivery.
 *
 * Concurrency: {@see OutboundRequestQueueProcessor} already applies a cross-request cooldown gate via CacheManager,
 * so two drains running within 300 s is the exceptional case. For extra safety, peekBatch() claims rows by setting
 * `locked_at = NOW()`. Stale locks (> 120 s old) are automatically reclaimed on the next peek.
 */
class MagentoRetryQueueStorage implements RetryQueueStorageInterface
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
            "INSERT IGNORE INTO {$table}
                (operation_type, payload, attempts, dedup_key, last_error, created_at, locked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $request->operationType,
                $this->serializer->serialize($request->payload),
                $request->attempts,
                $request->dedupKey(),
                $request->lastError,
                date('Y-m-d H:i:s', $request->createdAt),
                null,
            ]
        );
    }

    /**
     * @return QueuedRequest[]
     */
    public function peekBatch(int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $lockExpiry = date('Y-m-d H:i:s', time() - self::LOCK_TTL_SECONDS);

        // Select oldest unlocked (or stale-locked) rows by FIFO key.
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, ['request_id'])
                ->where('locked_at IS NULL OR locked_at < ?', $lockExpiry)
                ->order('request_id ASC')
                ->limit($limit)
        );

        if (empty($ids)) {
            return [];
        }

        // Atomically claim the selected rows.
        $connection->update(
            $table,
            ['locked_at' => date('Y-m-d H:i:s')],
            ['request_id IN (?)' => $ids]
        );

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

    public function count(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        return (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])
        );
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
        ]);
    }
}

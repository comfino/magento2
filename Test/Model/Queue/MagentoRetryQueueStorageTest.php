<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Queue;

use Comfino\Backend\Queue\QueuedRequest;
use Comfino\ComfinoGateway\Model\Queue\MagentoRetryQueueStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoRetryQueueStorageTest extends TestCase
{
    private const TABLE = 'comfino_request_queue';

    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private MagentoRetryQueueStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where', 'order', 'limit'])
            ->getMock();
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturn(self::TABLE);

        $this->storage = new MagentoRetryQueueStorage($this->resourceConnection);
    }

    private function makeRequest(
        int|string|null $id = 1,
        int $attempts = 0,
        ?string $lastError = null
    ): QueuedRequest {
        return new QueuedRequest($id, 'cancel_order', ['order_id' => '123'], $attempts, 1748000000, $lastError);
    }

    public function testCountReturnsParsedInteger(): void
    {
        $this->connection->method('fetchOne')->willReturn('7');

        $this->assertSame(7, $this->storage->count());
    }

    public function testCountReturnsZeroForEmptyTable(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');

        $this->assertSame(0, $this->storage->count());
    }

    public function testRemoveDeletesRowById(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('delete')
            ->with(self::TABLE, ['request_id = ?' => 1]);

        $this->storage->remove($this->makeRequest(1));
    }

    public function testUpdateWritesAttemptsAndReleasesLock(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                $this->callback(static function (array $data): bool {
                    return $data['attempts'] === 2
                        && $data['last_error'] === 'SomeException: oops'
                        && array_key_exists('locked_at', $data)
                        && $data['locked_at'] === null;
                }),
                ['request_id = ?' => 5]
            );

        $this->storage->update($this->makeRequest(5, 2, 'SomeException: oops'));
    }

    public function testEnqueueExecutesInsertIgnoreQuery(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('query')
            ->with($this->stringContains('INSERT IGNORE INTO'));

        $this->storage->enqueue($this->makeRequest(null));
    }

    public function testPeekBatchReturnsEmptyArrayWhenNoUnlockedRows(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->assertSame([], $this->storage->peekBatch(10));
    }

    public function testPeekBatchClainsRowsAndReturnsMappedRequests(): void
    {
        $this->connection->method('fetchCol')->willReturn([1, 2]);

        $this->connection->expects($this->once())->method('update')
            ->with(self::TABLE, $this->arrayHasKey('locked_at'));

        $this->connection->method('fetchAll')->willReturn([
            [
                'request_id' => '1',
                'operation_type' => 'cancel_order',
                'payload' => '{"order_id":"123"}',
                'attempts' => '0',
                'created_at' => '2026-01-01 10:00:00',
                'last_error' => null,
            ],
            [
                'request_id' => '2',
                'operation_type' => 'cancel_order',
                'payload' => '{"order_id":"456"}',
                'attempts' => '1',
                'created_at' => '2026-01-01 10:01:00',
                'last_error' => 'RuntimeException: timeout',
            ],
        ]);

        $rows = $this->storage->peekBatch(10);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]->id);
        $this->assertSame('cancel_order', $rows[0]->operationType);
        $this->assertSame(['order_id' => '123'], $rows[0]->payload);
        $this->assertSame(0, $rows[0]->attempts);
        $this->assertNull($rows[0]->lastError);
        $this->assertSame(2, $rows[1]->id);
        $this->assertSame(1, $rows[1]->attempts);
        $this->assertSame('RuntimeException: timeout', $rows[1]->lastError);
    }

    public function testPeekBatchDoesNotUpdateWhenNoRowsSelected(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->connection->expects($this->never())->method('update');

        $this->storage->peekBatch(5);
    }
}

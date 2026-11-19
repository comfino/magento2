<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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

    /** @var list<array{0: string, 1: mixed}> */
    private array $whereCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->whereCalls = [];

        $this->select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where', 'order', 'limit', 'group'])
            ->getMock();
        $this->select->method('from')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();
        $this->select->method('group')->willReturnSelf();
        // Record every where() so the tenant / due-time predicates can be asserted.
        $this->select->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null): Select {
                $this->whereCalls[] = [$condition, $value];

                return $this->select;
            }
        );

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
        ?string $lastError = null,
        ?string $tenantKey = null,
        int $availableAt = 0
    ): QueuedRequest {
        return new QueuedRequest(
            $id,
            'cancel_order',
            ['order_id' => '123'],
            $attempts,
            1748000000,
            $lastError,
            $tenantKey,
            $availableAt
        );
    }

    /**
     * @return list<string>
     */
    private function whereConditions(): array
    {
        return array_map(static fn(array $call): string => $call[0], $this->whereCalls);
    }

    private function boundValueFor(string $conditionFragment): mixed
    {
        foreach ($this->whereCalls as [$condition, $value]) {
            if (str_contains($condition, $conditionFragment)) {
                return $value;
            }
        }

        return null;
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
                    return $data['attempts'] === 2 &&
                        $data['last_error'] === 'SomeException: oops' &&
                        array_key_exists('locked_at', $data) &&
                        $data['locked_at'] === null &&
                        $data['available_at'] === 1748000900;
                }),
                ['request_id = ?' => 5]
            );

        $this->storage->update($this->makeRequest(5, 2, 'SomeException: oops', null, 1748000900));
    }

    public function testUpdatePersistsBackoffSoRetryPacingSurvives(): void
    {
        $captured = [];

        $this->connection->method('update')->willReturnCallback(
            static function (string $table, array $data) use (&$captured): int {
                $captured = $data;

                return 1;
            }
        );

        /* deferredTo() is what the processor calls when it pauses a tenant; the future
           timestamp it carries is only honored if update() writes it back. */
        $this->storage->update($this->makeRequest(9)->deferredTo('AuthException: 401', 1748009999));

        $this->assertSame(1748009999, $captured['available_at']);
        $this->assertSame(0, $captured['attempts'], 'deferredTo() must not burn an attempt');
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

    /* ---------------------------------------------------------------------
       * Multi-tenant / scheduling contract (RetryQueueStorageInterface 3.x)
       * ------------------------------------------------------------------ */

    public function testPeekBatchWithoutTenantDoesNotFilterOnTenantKey(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->storage->peekBatch(10);

        foreach ($this->whereConditions() as $condition) {
            $this->assertStringNotContainsString(
                'tenant_key',
                $condition,
                'A null tenant key means "every tenant" per the interface contract, not "the NULL tenant".'
            );
        }
    }

    public function testPeekBatchFiltersByTenantWhenGiven(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->storage->peekBatch(10, '7');

        $this->assertContains('tenant_key = ?', $this->whereConditions());
        $this->assertSame('7', $this->boundValueFor('tenant_key'));
    }

    public function testPeekBatchAppliesDueGateWhenGiven(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->storage->peekBatch(10, null, 1748000500);

        $this->assertContains('available_at <= ?', $this->whereConditions());
        $this->assertSame(1748000500, $this->boundValueFor('available_at'));
    }

    public function testPeekBatchOmitsDueGateWhenNotGiven(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->storage->peekBatch(10);

        $this->assertNotContains('available_at <= ?', $this->whereConditions());
    }

    public function testPeekBatchHydratesTenantKeyAndAvailableAt(): void
    {
        $this->connection->method('fetchCol')->willReturn([1]);
        $this->connection->method('fetchAll')->willReturn([
            [
                'request_id' => '1',
                'operation_type' => 'cancel_order',
                'payload' => '{"order_id":"123"}',
                'attempts' => '0',
                'created_at' => '2026-01-01 10:00:00',
                'last_error' => null,
                'tenant_key' => '7',
                'available_at' => '1748000900',
            ],
        ]);

        $rows = $this->storage->peekBatch(10);

        $this->assertSame('7', $rows[0]->tenantKey);
        $this->assertSame(1748000900, $rows[0]->availableAt);
    }

    public function testPeekBatchHydratesLegacyRowsWithoutNewColumns(): void
    {
        // A row written before tenant_key / available_at existed must still hydrate, as the null tenant, due now.
        $this->connection->method('fetchCol')->willReturn([1]);
        $this->connection->method('fetchAll')->willReturn([
            [
                'request_id' => '1',
                'operation_type' => 'cancel_order',
                'payload' => '{"order_id":"123"}',
                'attempts' => '0',
                'created_at' => '2026-01-01 10:00:00',
                'last_error' => null,
            ],
        ]);

        $rows = $this->storage->peekBatch(10);

        $this->assertNull($rows[0]->tenantKey);
        $this->assertSame(0, $rows[0]->availableAt);
        $this->assertTrue($rows[0]->isDue(time()));
    }

    public function testCountWithoutTenantCountsEveryRow(): void
    {
        $this->connection->method('fetchOne')->willReturn('7');

        $this->assertSame(7, $this->storage->count());
        $this->assertNotContains('tenant_key = ?', $this->whereConditions());
    }

    public function testCountFiltersByTenantWhenGiven(): void
    {
        $this->connection->method('fetchOne')->willReturn('3');

        $this->assertSame(3, $this->storage->count('7'));
        $this->assertContains('tenant_key = ?', $this->whereConditions());
        $this->assertSame('7', $this->boundValueFor('tenant_key'));
    }

    public function testCountOverdueSkipsLiveLocksAndComparesBothDueTimes(): void
    {
        $now = 1_700_000_000;
        $cutoff = $now - 1800;

        $this->connection->method('quote')->willReturnCallback(static fn (string $value): string => "'$value'");
        $this->connection->method('fetchOne')->willReturn('4');

        $this->assertSame(4, $this->storage->countOverdue(1800, $now));

        $conditions = array_column($this->whereCalls, 0);

        $this->assertContains('locked_at IS NULL OR locked_at < ?', $conditions);
        $this->assertContains(
            sprintf("(available_at > 0 AND available_at < %d) OR (available_at = 0 AND created_at < '%s')", $cutoff, date('Y-m-d H:i:s', $cutoff)),
            $conditions
        );
    }

    public function testPurgeDeletesByEveryGivenFilter(): void
    {
        $this->connection->expects($this->once())->method('delete')->with(self::TABLE, [
            'created_at < ?' => date('Y-m-d H:i:s', 1_700_000_000),
            'tenant_key = ?' => 'store:2',
            'operation_type = ?' => 'report_error',
        ])->willReturn(3);

        $this->assertSame(3, $this->storage->purge(1_700_000_000, 'store:2', 'report_error'));
    }

    public function testPurgeWithoutFiltersEmptiesTheQueue(): void
    {
        $this->connection->expects($this->once())->method('delete')->with(self::TABLE, [])->willReturn(5);

        $this->assertSame(5, $this->storage->purge());
    }

    public function testPendingTenantKeysReturnsEmptyArrayWhenQueueIsEmpty(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->storage->pendingTenantKeys());
    }

    public function testPendingTenantKeysReturnsNullKeyForSingleStoreInstall(): void
    {
        // Contract: a single-tenant store reports [null] when it has work, [] when it does not.
        $this->connection->method('fetchAll')->willReturn([
            ['tenant_key' => null, 'oldest_request_id' => '1'],
        ]);

        $this->assertSame([null], $this->storage->pendingTenantKeys());
    }

    public function testPendingTenantKeysPreservesOldestFirstOrdering(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['tenant_key' => '3', 'oldest_request_id' => '5'],
            ['tenant_key' => '7', 'oldest_request_id' => '9'],
        ]);

        $this->assertSame(['3', '7'], $this->storage->pendingTenantKeys());
    }

    public function testPendingTenantKeysCollapsesEmptyStringIntoNullTenant(): void
    {
        /* QueuedRequest::fromArray() normalizes '' to null, so a row stored with an empty tenant key is the same tenant
           as one stored with NULL. Reporting both would make the processor drain that partition twice. */
        $this->connection->method('fetchAll')->willReturn([
            ['tenant_key' => null, 'oldest_request_id' => '1'],
            ['tenant_key' => '', 'oldest_request_id' => '2'],
        ]);

        $this->assertSame([null], $this->storage->pendingTenantKeys());
    }

    public function testPendingTenantKeysAppliesDueGate(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->storage->pendingTenantKeys(1748000500);

        $this->assertContains('available_at <= ?', $this->whereConditions());
        $this->assertSame(1748000500, $this->boundValueFor('available_at'));
    }

    public function testPendingTenantKeysExcludesLockedRows(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->storage->pendingTenantKeys();

        $conditions = implode(' | ', $this->whereConditions());
        $this->assertStringContainsString('locked_at', $conditions, 'A tenant whose rows are all claimed by a concurrent drain has no work this cycle.');
    }

    public function testEnqueuePersistsTenantKeyAndAvailableAt(): void
    {
        $bind = [];

        $this->connection->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$bind): mixed {
                $bind = $params;

                return null;
            }
        );

        $this->storage->enqueue($this->makeRequest(null, 0, null, '7', 1748000900));

        $this->assertContains('7', $bind, 'tenant_key must be bound into the INSERT.');
        $this->assertContains(1748000900, $bind, 'available_at must be bound into the INSERT.');
    }

    public function testEnqueueBindsTenantScopedDedupKey(): void
    {
        $bind = [];

        $this->connection->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$bind): mixed {
                $bind = $params;

                return null;
            }
        );

        $request = $this->makeRequest(null, 0, null, '7');
        $this->storage->enqueue($request);

        /* The dedup key is tenant-scoped in 3.x, which is the whole point: two stores cancelling their own order "123" must not collapse
           into one queue row. Asserting the bound value tracks the SDK's format rather than restating it here. */
        $this->assertContains($request->dedupKey(), $bind);
        $this->assertStringStartsWith('7:', $request->dedupKey());
        $this->assertNotSame(
            $request->dedupKey(),
            $this->makeRequest(null, 0, null, '8')->dedupKey(),
            'Two stores with the same payload must produce different dedup keys.'
        );
    }
}

<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Queue;

use Comfino\ComfinoGateway\Model\Queue\MagentoRetryQueueStorage;
use Comfino\ComfinoGateway\Model\Queue\QueueHealth;
use Comfino\ComfinoGateway\Model\Queue\QueueState;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueHealthTest extends TestCase
{
    private ?QueueState $queueState = null;
    private ?StoreManagerInterface $storeManager = null;

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testReportsTheOverdueCountOnceAndCachesIt(): void
    {
        ConfigManagerHarness::install(['COMFINO_RETRY_QUEUE_ENABLED' => true]);

        $storage = $this->createMock(MagentoRetryQueueStorage::class);
        $storage->expects($this->once())->method('countOverdue')->with(QueueHealth::OVERDUE_SECONDS)->willReturn(3);

        $health = $this->createHealth($storage);

        $first = $health->getOverdueCount();
        $second = $health->getOverdueCount();

        $this->assertSame([3, 3], [$first, $second]);
    }

    public function testReportsNothingWhenTheQueueIsDisabled(): void
    {
        ConfigManagerHarness::install(['COMFINO_RETRY_QUEUE_ENABLED' => false]);

        $storage = $this->createMock(MagentoRetryQueueStorage::class);
        $storage->expects($this->never())->method('countOverdue');

        $this->assertSame(0, $this->createHealth($storage)->getOverdueCount());
    }

    public function testReportsNothingWhenTheQueueCannotBeRead(): void
    {
        ConfigManagerHarness::install(['COMFINO_RETRY_QUEUE_ENABLED' => true]);

        $storage = $this->createMock(MagentoRetryQueueStorage::class);
        $storage->method('countOverdue')->willThrowException(new RuntimeException('Table missing'));

        $this->assertSame(0, $this->createHealth($storage)->getOverdueCount());
    }

    private function createHealth(MagentoRetryQueueStorage $storage): QueueHealth
    {
        return new class ($this->createMock(ResourceConnection::class), $storage, $this->queueState ?? $this->createMock(QueueState::class), $this->storeManager ?? $this->createMock(StoreManagerInterface::class)) extends QueueHealth {
            public function __construct(
                ResourceConnection $resourceConnection,
                private readonly MagentoRetryQueueStorage $storage,
                QueueState $queueState,
                StoreManagerInterface $storeManager
            ) {
                parent::__construct($resourceConnection, $queueState, $storeManager);
            }

            protected function createStorage(): MagentoRetryQueueStorage
            {
                return $this->storage;
            }
        };
    }

    public function testNamesPausedStores(): void
    {
        $this->queueState = $this->createMock(QueueState::class);
        $this->queueState->method('getPausedTenants')->willReturn([
            'store:3' => ['reason' => 'credentials_rejected', 'at' => time()],
            '' => ['reason' => 'consecutive_failures', 'at' => time()],
        ]);

        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('Outlet');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->with(3)->willReturn($store);

        $this->assertSame(
            ['Outlet (ID 3)' => 'credentials_rejected', 'Default store view' => 'consecutive_failures'],
            $this->createHealth($this->createMock(MagentoRetryQueueStorage::class))->getPausedStores()
        );
    }

    public function testPendingCountIsZeroWhenTheQueueCannotBeRead(): void
    {
        $storage = $this->createMock(MagentoRetryQueueStorage::class);
        $storage->method('count')->willThrowException(new RuntimeException('Table missing'));

        $this->assertSame(0, $this->createHealth($storage)->getPendingCount());
    }
}

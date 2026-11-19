<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Setup
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Setup;

use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\ComfinoGateway\Setup\Uninstall;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    private string $logDir;
    private ConfigCache&MockObject $configCache;
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/comfino_uninstall_' . bin2hex(random_bytes(4));
        mkdir($this->logDir);

        $this->configCache = $this->createMock(ConfigCache::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        rmdir($this->logDir);

        parent::tearDown();
    }

    private function createUninstall(): Uninstall
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(DirectoryList::LOG)->willReturn($this->logDir);

        return new Uninstall($directoryList, new File(), $this->cache, $this->configCache);
    }
    /**
     * Builds a Select mock whose fluent from()/where() return itself, so the uninstaller can chain freely.
     */
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeSetup(AdapterInterface $connection): SchemaSetupInterface
    {
        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        $setup->expects($this->once())->method('startSetup');
        $setup->expects($this->once())->method('endSetup');

        return $setup;
    }

    public function testUninstallAlwaysUnassignsStatesAndDeletesUnusedStatuses(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->makeSelect());

        /* No status is referenced by any order or history row: every status row is hard-deleted. */
        $connection->method('fetchOne')->willReturn('0');

        $deletedFromStateTable = [];
        $deletedStatusCodes = [];

        $connection->method('delete')->willReturnCallback(
            function (string $table, mixed $where = '') use (&$deletedFromStateTable, &$deletedStatusCodes): int {
                if ($table === 'sales_order_status_state') {
                    $deletedFromStateTable[] = $where;
                } elseif ($table === 'sales_order_status') {
                    $deletedStatusCodes[] = $where['status = ?'];
                }

                return 1;
            }
        );

        $this->createUninstall()->uninstall($this->makeSetup($connection), $this->createMock(ModuleContextInterface::class));

        $expectedCodes = array_keys(ShopStatusManager::CUSTOM_STATUS_LABELS);

        /* The bulk state-table unassignment fires exactly once for all status codes. */
        $this->assertCount(1, $deletedFromStateTable);
        $this->assertSame($expectedCodes, $deletedFromStateTable[0]['status IN (?)']);

        /* Every status code is removed from sales_order_status because none is in use. */
        $this->assertSame($expectedCodes, $deletedStatusCodes);
    }

    public function testUninstallKeepsStatusRowsStillReferencedByOrdersOrHistory(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->makeSelect());

        /* Every lookup reports the status is still in use, so no status row is hard-deleted. */
        $connection->method('fetchOne')->willReturn('1');

        $deletedFromStateTable = 0;

        $connection->method('delete')->willReturnCallback(
            function (string $table) use (&$deletedFromStateTable): int {
                $this->assertNotSame('sales_order_status', $table, 'In-use status rows must not be deleted.');

                if ($table === 'sales_order_status_state') {
                    $deletedFromStateTable++;
                }

                return 1;
            }
        );

        $this->createUninstall()->uninstall($this->makeSetup($connection), $this->createMock(ModuleContextInterface::class));

        /* States are always unassigned even when the status rows themselves are retained. */
        $this->assertSame(1, $deletedFromStateTable);
    }

    public function testUninstallRemovesSettingsQueueCacheAndLogs(): void
    {
        foreach (['comfino_debug-2026-11-17.log', 'comfino_debug-legacy.log', 'comfino_errors.log', 'system.log'] as $file) {
            file_put_contents($this->logDir . '/' . $file, 'x');
        }

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->makeSelect());
        $connection->method('fetchOne')->willReturn('1');
        $connection->method('isTableExists')->with('comfino_request_queue')->willReturn(true);

        $deletes = [];
        $connection->method('delete')->willReturnCallback(
            function (string $table, mixed $where = '') use (&$deletes): int {
                $deletes[$table] = $where;

                return 1;
            }
        );

        $this->configCache->expects($this->once())->method('clean')
            ->with(\Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, ['comfino', 'comfino_queue']);
        $this->cache->expects($this->once())->method('clean')->with([LoanSelection::CACHE_TAG]);

        $this->createUninstall()->uninstall($this->makeSetup($connection), $this->createMock(ModuleContextInterface::class));

        $this->assertSame(['path LIKE ?' => 'payment/comfino/%'], $deletes['core_config_data']);
        $this->assertSame(['flag_code = ?' => 'comfino_update_notice_dismissed'], $deletes['flag']);
        $this->assertArrayHasKey('comfino_request_queue', $deletes);
        $this->assertSame([$this->logDir . '/system.log'], glob($this->logDir . '/*'));
    }
}

<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Setup\Patch\Data;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\ComfinoGateway\Setup\Patch\Data\AddComfinoOrderStatuses;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

final class AddComfinoOrderStatusesTest extends TestCase
{
    /**
     * Builds a Select mock whose fluent from()/where() return itself, so the patch can chain freely.
     */
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeSetup(AdapterInterface $connection): ModuleDataSetupInterface
    {
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        return $setup;
    }

    public function testHasNoDependenciesOrAliases(): void
    {
        $patch = new AddComfinoOrderStatuses($this->createMock(ModuleDataSetupInterface::class));

        $this->assertSame([], AddComfinoOrderStatuses::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testApplyInsertsEveryStatusAndStateWhenNoneExist(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('startSetup');
        $connection->expects($this->once())->method('endSetup');
        $connection->method('select')->willReturnCallback(fn (): Select => $this->makeSelect());

        /* Nothing exists yet: every fetchOne returns false → both inserts fire per status. */
        $connection->method('fetchOne')->willReturn(false);

        $insertedStatusCodes = [];
        $insertedStateRows = [];

        $connection->method('insert')->willReturnCallback(
            function (string $table, array $data) use (&$insertedStatusCodes, &$insertedStateRows): int {
                if ($table === 'sales_order_status') {
                    $insertedStatusCodes[] = $data['status'];
                } elseif ($table === 'sales_order_status_state') {
                    $insertedStateRows[] = $data;
                }

                return 1;
            }
        );

        $patch = new AddComfinoOrderStatuses($this->makeSetup($connection));
        $result = $patch->apply();

        $this->assertSame($patch, $result);

        $expectedCodes = array_keys(ShopStatusManager::CUSTOM_STATUS_LABELS);
        $this->assertSame($expectedCodes, $insertedStatusCodes);
        $this->assertCount(count($expectedCodes), $insertedStateRows);

        foreach ($insertedStateRows as $row) {
            $this->assertSame(0, $row['is_default']);
            $this->assertSame(1, $row['visible_on_front']);
            $this->assertArrayHasKey('state', $row);
            $this->assertArrayHasKey('status', $row);
        }
    }

    public function testApplySkipsInsertsWhenStatusAndStateAlreadyExist(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->makeSelect());

        /* Everything already present: fetchOne returns a truthy value for both lookups → no inserts. */
        $connection->method('fetchOne')->willReturn('existing');
        $connection->expects($this->never())->method('insert');

        $patch = new AddComfinoOrderStatuses($this->makeSetup($connection));

        $this->assertSame($patch, $patch->apply());
    }
}
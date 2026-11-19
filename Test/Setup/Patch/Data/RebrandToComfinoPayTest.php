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

namespace Comfino\Tests\Setup\Patch\Data;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\ComfinoGateway\Setup\Patch\Data\RebrandToComfinoPay;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

final class RebrandToComfinoPayTest extends TestCase
{
    public function testRelabelsOnlyUnchangedDefaults(): void
    {
        $updates = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(
            function (string $table, array $bind, array $where) use (&$updates): int {
                $updates[] = [$table, $bind, $where];

                return 1;
            }
        );

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        (new RebrandToComfinoPay($setup))->apply();

        $this->assertCount(count(ShopStatusManager::CUSTOM_STATUS_LABELS) + 1, $updates);
        $this->assertSame(
            ['sales_order_status', ['label' => 'Credit granted (ComfinoPay)'], ['status = ?' => 'comfino_accepted', 'label = ?' => 'Credit granted (Comfino)']],
            $updates[1]
        );
        $this->assertSame(
            [
                'core_config_data',
                ['value' => 'ComfinoPay'],
                [
                    'path IN (?)' => ['payment/comfino/title', 'payment/comfino/payment_text'],
                    'TRIM(value) IN (?)' => [
                        'Comfino',
                        '(Raty | Kup Teraz, Zapłać Później | Finansowanie dla Firm)',
                        'Comfino (Raty | Kup Teraz, Zapłać Później | Finansowanie dla Firm)',
                    ],
                ],
            ],
            end($updates)
        );
    }
}

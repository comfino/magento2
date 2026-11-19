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

use Comfino\ComfinoGateway\Setup\Patch\Data\EncryptComfinoApiKeys;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

final class EncryptComfinoApiKeysTest extends TestCase
{
    public function testEncryptsOnlyPlainNonEmptyKeys(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['config_id' => 1, 'value' => 'plain-production-key'],
            ['config_id' => 2, 'value' => '0:3:already-encrypted'],
            ['config_id' => 3, 'value' => ''],
            ['config_id' => 4, 'value' => null],
        ]);
        $connection->expects($this->once())->method('update')
            ->with('core_config_data', ['value' => 'ENC(plain-production-key)'], ['config_id = ?' => 1]);

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => "ENC($value)");

        (new EncryptComfinoApiKeys($setup, $encryptor))->apply();
    }
}

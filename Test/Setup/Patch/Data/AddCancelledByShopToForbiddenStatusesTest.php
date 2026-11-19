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

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Setup\Patch\Data\AddCancelledByShopToForbiddenStatuses;
use Comfino\Shop\Order\StatusManager;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

final class AddCancelledByShopToForbiddenStatusesTest extends TestCase
{
    public function testHasNoDependenciesOrAliases(): void
    {
        $patch = new AddCancelledByShopToForbiddenStatuses($this->createMock(ModuleDataSetupInterface::class));

        $this->assertSame([], AddCancelledByShopToForbiddenStatuses::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testUpdatesOnlyListsStillEqualToTheOldDefault(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('startSetup');
        $connection->expects($this->once())->method('endSetup');
        $connection->expects($this->once())
            ->method('update')
            ->with(
                'core_config_data',
                ['value' => 'RESIGN,CANCELLED_BY_SHOP'],
                ['path = ?' => Data::XML_PATH_FORBIDDEN_STATUSES, 'TRIM(value) = ?' => 'RESIGN']
            )
            ->willReturn(2);

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $patch = new AddCancelledByShopToForbiddenStatuses($setup);

        $this->assertSame($patch, $patch->apply());
    }

    public function testNewDefaultMatchesTheSdkDefaultAndConfigXml(): void
    {
        $sdkDefault = implode(',', array_map(
            static fn ($status): string => $status->getValue(),
            StatusManager::DEFAULT_FORBIDDEN_STATUSES
        ));

        $this->assertSame(AddCancelledByShopToForbiddenStatuses::NEW_DEFAULT, $sdkDefault);

        $configXmlContents = file_get_contents(dirname(__DIR__, 4) . '/etc/config.xml');

        $this->assertIsString($configXmlContents);

        // simplexml_load_file() fails when libxml_disable_entity_loader(true) was called (PHP bug #62577).
        $configXml = simplexml_load_string($configXmlContents);

        $this->assertInstanceOf(\SimpleXMLElement::class, $configXml);
        $this->assertSame(
            AddCancelledByShopToForbiddenStatuses::NEW_DEFAULT,
            (string) $configXml->default->payment->comfino->forbidden_statuses
        );
    }
}

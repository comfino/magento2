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

namespace Comfino\Tests\Model\Config\Backend;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Config\Backend\RemoveSandboxApiKey;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

final class RemoveSandboxApiKeyTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    private function makeField(string $value, WriterInterface&MockObject $writer): RemoveSandboxApiKey
    {
        $field = (new ReflectionClass(RemoveSandboxApiKey::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, ['value' => $value, 'scope' => 'stores', 'scope_id' => 5]);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));
        (new ReflectionProperty($field, 'cacheTypeList'))->setValue($field, $this->createMock(TypeListInterface::class));
        (new ReflectionProperty($field, 'configWriter'))->setValue($field, $writer);
        (new ReflectionProperty($field, '_config'))->setValue($field, $this->createMock(ScopeConfigInterface::class));

        return $field;
    }

    /**
     * @throws ReflectionException
     */
    public function testYesDeletesTheSandboxKeyOfTheScopeAndResetsTheSwitch(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('delete')->with(Data::XML_PATH_SANDBOX_API_KEY, 'stores', 5);

        $field = $this->makeField('1', $writer);
        $field->beforeSave();
        $field->afterSave();

        $this->assertSame('0', $field->getValue());
    }

    /**
     * @throws ReflectionException
     */
    public function testNoKeepsTheKey(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('delete');

        $field = $this->makeField('0', $writer);
        $field->beforeSave();
        $field->afterSave();
    }
}

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

use Comfino\ComfinoGateway\Model\Config\Backend\RequiredField;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * RequiredField rejects an empty value; built without the Value constructor, see NumericFieldTest.
 */
final class RequiredFieldTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws ReflectionException
     */
    private function makeField(array $data): RequiredField
    {
        $field = (new ReflectionClass(RequiredField::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, $data);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));

        return $field;
    }

    /**
     * @throws ReflectionException
     */
    public function testThrowsWhenValueEmptyUsingLabel(): void
    {
        $field = $this->makeField(['value' => '   ', 'field_config' => ['label' => 'API key']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"API key" cannot be empty.');

        $field->beforeSave();
    }

    /**
     * @throws ReflectionException
     */
    public function testThrowsWhenValueEmptyFallingBackToPath(): void
    {
        $field = $this->makeField(['value' => '', 'path' => 'payment/comfino/api_key']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"payment/comfino/api_key" cannot be empty.');

        $field->beforeSave();
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testAcceptsNonEmptyValue(): void
    {
        $field = $this->makeField(['value' => 'secret', 'field_config' => ['label' => 'API key']]);

        $this->assertSame($field, $field->beforeSave());
    }
}

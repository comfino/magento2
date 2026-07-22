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

namespace Comfino\Tests\Model\Config\Backend;

use Comfino\ComfinoGateway\Model\Config\Backend\OptionalNumericField;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * OptionalNumericField allows an empty value but rejects a non-numeric one. Built via
 * newInstanceWithoutConstructor() to bypass the Value constructor; see NumericFieldTest for the rationale.
 */
final class OptionalNumericFieldTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function makeField(array $data): OptionalNumericField
    {
        $field = (new ReflectionClass(OptionalNumericField::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, $data);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));

        return $field;
    }

    public function testAcceptsEmptyValue(): void
    {
        $field = $this->makeField(['value' => '  ', 'field_config' => ['label' => 'Limit']]);

        $this->assertSame($field, $field->beforeSave());
    }

    public function testAcceptsNumericValue(): void
    {
        $field = $this->makeField(['value' => '12.5', 'field_config' => ['label' => 'Limit']]);

        $this->assertSame($field, $field->beforeSave());
    }

    public function testThrowsWhenValueNotNumeric(): void
    {
        $field = $this->makeField(['value' => 'xyz', 'path' => 'payment/comfino/limit']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"payment/comfino/limit" must be a numeric value.');

        $field->beforeSave();
    }
}
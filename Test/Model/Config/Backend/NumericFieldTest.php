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

use Comfino\ComfinoGateway\Model\Config\Backend\NumericField;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * NumericField::beforeSave() validates the stored config value. The validation branches throw before reaching
 * parent::beforeSave(), so they need no Magento services. The happy path falls through to parent::beforeSave()
 * (AbstractModel), which only dispatches events — a mocked event manager is injected for that.
 *
 * The model is built via newInstanceWithoutConstructor() to bypass the ~10-service Value constructor; the few data
 * properties the method reads (value, path, field_config) are seeded directly into the DataObject store.
 */
final class NumericFieldTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function makeField(array $data): NumericField
    {
        $field = (new ReflectionClass(NumericField::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, $data);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));

        return $field;
    }

    public function testThrowsWhenValueIsEmpty(): void
    {
        $field = $this->makeField(['value' => '  ', 'field_config' => ['label' => 'Hours']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"Hours" cannot be empty.');

        $field->beforeSave();
    }

    public function testThrowsWhenValueNotNumeric(): void
    {
        $field = $this->makeField(['value' => 'abc', 'field_config' => ['label' => 'Hours']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"Hours" must be a numeric value.');

        $field->beforeSave();
    }

    public function testFallsBackToPathWhenLabelMissing(): void
    {
        $field = $this->makeField(['value' => '', 'path' => 'payment/comfino/timeout']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"payment/comfino/timeout" cannot be empty.');

        $field->beforeSave();
    }

    public function testAcceptsNumericValue(): void
    {
        $field = $this->makeField(['value' => '42', 'field_config' => ['label' => 'Hours']]);

        $this->assertSame($field, $field->beforeSave());
    }
}
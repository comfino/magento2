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

namespace Comfino\Tests\Block\Adminhtml\System\Config;

use Comfino\ComfinoGateway\Block\Adminhtml\System\Config\DevEnvField;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * Only the suppressed branch (COMFINO_DEV_ENV not 'TRUE') is tested; the other needs a full layout stack.
 */
final class DevEnvFieldTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $value = getenv('COMFINO_DEV_ENV');
        $this->originalEnv = $value === false ? null : $value;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === null) {
            putenv('COMFINO_DEV_ENV');
        } else {
            putenv('COMFINO_DEV_ENV=' . $this->originalEnv);
        }

        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnsEmptyStringWhenDevEnvNotEnabled(): void
    {
        putenv('COMFINO_DEV_ENV=FALSE');

        $field = (new ReflectionClass(DevEnvField::class))->newInstanceWithoutConstructor();

        $this->assertSame('', $field->render($this->createMock(AbstractElement::class)));
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnsEmptyStringWhenDevEnvUnset(): void
    {
        putenv('COMFINO_DEV_ENV');

        $field = (new ReflectionClass(DevEnvField::class))->newInstanceWithoutConstructor();

        $this->assertSame('', $field->render($this->createMock(AbstractElement::class)));
    }
}

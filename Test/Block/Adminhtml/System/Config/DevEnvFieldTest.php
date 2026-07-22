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

namespace Comfino\Tests\Block\Adminhtml\System\Config;

use Comfino\ComfinoGateway\Block\Adminhtml\System\Config\DevEnvField;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DevEnvField::render() suppresses its output unless the COMFINO_DEV_ENV environment variable equals 'TRUE'. Only the
 * suppressed branch is exercised here: the field is built via newInstanceWithoutConstructor() to skip the Magento Field
 * constructor. The opposite branch defers to parent::render(), which requires a full layout/escaper stack and belongs
 * to integration tests.
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

    public function testReturnsEmptyStringWhenDevEnvNotEnabled(): void
    {
        putenv('COMFINO_DEV_ENV=FALSE');

        $field = (new ReflectionClass(DevEnvField::class))->newInstanceWithoutConstructor();

        $this->assertSame('', $field->render($this->createMock(AbstractElement::class)));
    }

    public function testReturnsEmptyStringWhenDevEnvUnset(): void
    {
        putenv('COMFINO_DEV_ENV');

        $field = (new ReflectionClass(DevEnvField::class))->newInstanceWithoutConstructor();

        $this->assertSame('', $field->render($this->createMock(AbstractElement::class)));
    }
}
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
use Comfino\ComfinoGateway\Model\Config\Backend\ApiKey;
use Comfino\ComfinoGateway\Model\Config\Backend\ReplacedApiKeys;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * Built via newInstanceWithoutConstructor() to bypass the Value constructor; see NumericFieldTest for the rationale.
 */
final class ApiKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        ReplacedApiKeys::reset();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ReflectionException
     */
    private function makeField(array $data, string $storedValue = '0:3:old'): ApiKey
    {
        $field = (new ReflectionClass(ApiKey::class))->newInstanceWithoutConstructor();

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => '0:3:enc(' . $value . ')');

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($storedValue);

        (new ReflectionProperty($field, '_data'))->setValue($field, $data + ['scope' => 'websites', 'scope_id' => 2]);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));
        (new ReflectionProperty($field, '_encryptor'))->setValue($field, $encryptor);
        (new ReflectionProperty($field, '_config'))->setValue($field, $config);

        return $field;
    }

    /**
     * @throws ReflectionException
     */
    private function saveAllowed(ApiKey $field): bool
    {
        return (new ReflectionProperty($field, '_dataSaveAllowed'))->getValue($field);
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testANewKeyIsStoredEncryptedAndThePreviousOneRemembered(): void
    {
        $field = $this->makeField(['value' => ' new-key-0000000001 ', 'path' => Data::XML_PATH_API_KEY]);

        $field->beforeSave();

        $this->assertTrue($this->saveAllowed($field));
        $this->assertSame('0:3:enc(new-key-0000000001)', $field->getValue());
        $this->assertSame('0:3:old', ReplacedApiKeys::previous(Data::XML_PATH_API_KEY, 'websites', 2));
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testThePlaceholderKeepsTheSavedKey(): void
    {
        $field = $this->makeField(['value' => '******', 'path' => Data::XML_PATH_API_KEY]);

        $field->beforeSave();

        $this->assertFalse($this->saveAllowed($field));
        $this->assertNull(ReplacedApiKeys::previous(Data::XML_PATH_API_KEY, 'websites', 2));
    }

    /**
     * @throws ReflectionException
     */
    public function testTheProductionKeyIsRequired(): void
    {
        $field = $this->makeField(['value' => '  ', 'path' => Data::XML_PATH_API_KEY, 'field_config' => ['label' => 'Production API key']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"Production API key" cannot be empty.');

        $field->beforeSave();
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testAnEmptySandboxFieldKeepsTheSavedKey(): void
    {
        $field = $this->makeField(['value' => '', 'path' => Data::XML_PATH_SANDBOX_API_KEY]);

        $field->beforeSave();

        $this->assertFalse($this->saveAllowed($field));
    }
}

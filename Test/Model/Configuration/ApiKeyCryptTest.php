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

namespace Comfino\Tests\Model\Configuration;

use Comfino\ComfinoGateway\Model\Configuration\ApiKeyCrypt;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiKeyCryptTest extends TestCase
{
    private function encryptor(): EncryptorInterface&MockObject
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => '0:3:' . base64_encode($value));
        $encryptor->method('decrypt')->willReturnCallback(static fn (string $value): string => (string) base64_decode(substr($value, 4)));

        return $encryptor;
    }

    public function testRoundTrip(): void
    {
        $encrypted = ApiKeyCrypt::encrypt(' plain-api-key-0001 ', $this->encryptor());

        $this->assertTrue(ApiKeyCrypt::isEncrypted($encrypted));
        $this->assertSame('plain-api-key-0001', ApiKeyCrypt::decrypt($encrypted, $this->encryptor()));
    }

    public function testAPlainLegacyValueIsReadAsIs(): void
    {
        $this->assertSame('legacy-plain-key', ApiKeyCrypt::decrypt('legacy-plain-key', $this->encryptor()));
    }

    public function testAnEncryptedValueIsNotEncryptedTwice(): void
    {
        $this->assertSame('0:3:abc', ApiKeyCrypt::encrypt('0:3:abc', $this->encryptor()));
    }

    public function testAnUndecryptableValueReadsAsEmpty(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willThrowException(new RuntimeException('bad key'));

        $this->assertSame('', ApiKeyCrypt::decrypt('0:3:abc', $encryptor));
    }

    public function testEmptyStaysEmpty(): void
    {
        $this->assertSame('', ApiKeyCrypt::encrypt('', $this->encryptor()));
        $this->assertSame('', ApiKeyCrypt::decrypt(null, $this->encryptor()));
    }

    public function testTailShowsAtMostAQuarterOfTheKey(): void
    {
        $this->assertSame('abcd', ApiKeyCrypt::tail('0123456789abcdef0123456789abcd'));
        $this->assertSame('cd', ApiKeyCrypt::tail('abcdefabcd'));
        $this->assertSame('', ApiKeyCrypt::tail('abc'));
    }
}

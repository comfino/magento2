<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Magento\Framework\Encryption\EncryptorInterface;
use Throwable;

/**
 * Reads the stored API keys, encrypted with the Magento encryption key. A value without the encryption prefix (stored
 * by 4.0.x before the upgrade patch ran) is returned as is.
 */
final class ApiKeyCrypt
{
    /** Magento's encrypted format: "<key version>:<cipher version>:<payload>". */
    private const ENCRYPTED_PATTERN = '/^\d+:\d+:\S+$/';

    public static function isEncrypted(string $value): bool
    {
        return preg_match(self::ENCRYPTED_PATTERN, $value) === 1;
    }

    /**
     * Returns the plain API key, or '' when the stored value is empty or cannot be decrypted (e.g. after the Magento
     * encryption key was replaced without re-encrypting).
     */
    public static function decrypt(mixed $storedValue, ?EncryptorInterface $encryptor): string
    {
        $storedValue = trim((string) $storedValue);

        if ($storedValue === '' || $encryptor === null || !self::isEncrypted($storedValue)) {
            return $storedValue;
        }

        try {
            return trim((string) $encryptor->decrypt($storedValue));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Encrypts a plain API key for storage; an empty or already encrypted value is returned unchanged.
     */
    public static function encrypt(mixed $plainValue, ?EncryptorInterface $encryptor): string
    {
        $plainValue = trim((string) $plainValue);

        if ($plainValue === '' || $encryptor === null || self::isEncrypted($plainValue)) {
            return $plainValue;
        }

        return $encryptor->encrypt($plainValue);
    }

    /**
     * Last characters of a key for display ("…abcd"); never more than a quarter of the key.
     */
    public static function tail(string $plainValue): string
    {
        $length = min(4, intdiv(strlen($plainValue), 4));

        return $length > 0 ? substr($plainValue, -$length) : '';
    }
}

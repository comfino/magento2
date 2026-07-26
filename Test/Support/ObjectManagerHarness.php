<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Support;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Installs a minimal ObjectManager backed by an explicit type => instance map.
 *
 * Static facades in this module resolve their collaborators through ObjectManager::getInstance(), which throws
 * outside a booted Magento application. Register only what the code under test actually asks for: an unexpected
 * lookup raises instead of silently returning a null-ish object, so a widened dependency surface shows up as a test
 * failure rather than as a confusing downstream error.
 */
final class ObjectManagerHarness
{
    /**
     * @param array<class-string, object> $instances Type name => instance returned by get()/create()
     */
    public static function install(array $instances): void
    {
        ObjectManager::setInstance(
            new class ($instances) implements ObjectManagerInterface {
                /** @param array<class-string, object> $instances */
                public function __construct(private readonly array $instances)
                {
                }

                /**
                 * @param string $type
                 * @param array<string, mixed> $arguments
                 */
                public function create($type, array $arguments = [])
                {
                    return $this->get($type);
                }

                /** @param string $type */
                public function get($type)
                {
                    if (!isset($this->instances[$type])) {
                        throw new RuntimeException("No test instance registered for \"$type\".");
                    }

                    return $this->instances[$type];
                }

                /** @param array<string, mixed> $configuration */
                public function configure(array $configuration)
                {
                }
            }
        );
    }

    public static function reset(): void
    {
        (new ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
    }
}
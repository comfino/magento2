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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use ReflectionProperty;

/**
 * Test harness that swaps the SDK ConfigurationManager backing ConfigManager with a controllable in-memory instance.
 *
 * This avoids touching Magento's ObjectManager / scope config while letting tests drive flags such as debug mode,
 * sandbox mode, and the configured initial order status.
 */
final class ConfigManagerHarness
{
    /**
     * Installs an in-memory ConfigurationManager seeded with the given option values.
     *
     * @param array<string, mixed> $values Map of COMFINO_* option keys to their stored values.
     */
    public static function install(array $values): void
    {
        ConfigurationManager::reset();

        $availOptions = array_merge(...array_values(ConfigManager::CONFIG_OPTIONS));

        $storage = new class ($values) implements StorageAdapterInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function load(): array
            {
                return $this->values;
            }

            public function save(array $configurationOptions): void
            {
                /* No-op: the harness never persists. */
            }
        };

        $manager = ConfigurationManager::getInstance(
            $availOptions,
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            $storage,
            new JsonSerializer()
        );

        $manager->setDefaults(ConfigManager::getDefaultConfigurationValues());

        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, $manager);
    }

    /**
     * Installs an in-memory ConfigurationManager for one specific store scope.
     *
     * Mirrors {@see install()} but targets ConfigManager::$storeScopedManagers, so tests can give different stores
     * different API keys / sandbox flags and assert that store-scoped reads stay isolated from the ambient scope.
     *
     * @param array<string, mixed> $values Map of COMFINO_* option keys to their stored values.
     */
    public static function installForStore(int $storeId, array $values): void
    {
        $availOptions = array_merge(...array_values(ConfigManager::CONFIG_OPTIONS));

        $storage = new class ($values) implements StorageAdapterInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function load(): array
            {
                return $this->values;
            }

            public function save(array $configurationOptions): void
            {
                /* No-op: the harness never persists. */
            }
        };

        $manager = ConfigurationManager::getInstance(
            $availOptions,
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            $storage,
            new JsonSerializer(),
            'test-store:' . $storeId
        );

        $manager->setDefaults(ConfigManager::getDefaultConfigurationValues());

        $property = new ReflectionProperty(ConfigManager::class, 'storeScopedManagers');
        $managers = $property->getValue();
        $managers[$storeId] = $manager;
        $property->setValue(null, $managers);
    }

    /**
     * Removes the in-memory ConfigurationManagers (ambient and per-store) so later tests rebuild their own.
     */
    public static function reset(): void
    {
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);
        (new ReflectionProperty(ConfigManager::class, 'storeScopedManagers'))->setValue(null, []);
        ConfigurationManager::reset();
    }
}
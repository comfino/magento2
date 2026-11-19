<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
 * Test harness that swaps the SDK ConfigurationManager backing ConfigManager with an in-memory instance, avoiding
 * Magento's ObjectManager and scope config.
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
     * Mirrors {@see install()} but targets ConfigManager::$storeScopedManagers, so store-scoped reads stay isolated.
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
     * Installs an in-memory ConfigurationManager for default config or a website, as returned by ConfigManager::getScopedInstance()
     * (store views go through {@see installForStore()}).
     *
     * @param array<string, mixed> $values Map of COMFINO_* option keys to their stored values.
     */
    public static function installForScope(string $scope, int $scopeId, array $values): void
    {
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
                // No-op: the harness never persists.
            }
        };

        $manager = ConfigurationManager::getInstance(
            array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)),
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            $storage,
            new JsonSerializer(),
            'test-' . $scope . ':' . $scopeId
        );

        $manager->setDefaults(ConfigManager::getDefaultConfigurationValues());

        $property = new ReflectionProperty(ConfigManager::class, 'scopedManagers');

        $managers = $property->getValue();
        $managers[$scope . ':' . $scopeId] = $manager;

        $property->setValue(null, $managers);
    }

    /**
     * Removes the in-memory ConfigurationManagers (ambient, per-store and per-scope) so later tests rebuild their own.
     */
    public static function reset(): void
    {
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);
        (new ReflectionProperty(ConfigManager::class, 'storeScopedManagers'))->setValue(null, []);
        (new ReflectionProperty(ConfigManager::class, 'scopedManagers'))->setValue(null, []);
        ConfigurationManager::reset();
    }
}

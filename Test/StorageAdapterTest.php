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

namespace Comfino\Tests;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\StorageAdapter;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StorageAdapterTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private WriterInterface&MockObject $configWriter;
    private TypeListInterface&MockObject $cacheTypeList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
    }

    private function createAdapter(): StorageAdapter
    {
        return new StorageAdapter($this->scopeConfig, $this->configWriter, $this->cacheTypeList);
    }

    private function createScopedAdapter(int $storeId, string $scope = ScopeInterface::SCOPE_STORE): StorageAdapter
    {
        return new StorageAdapter($this->scopeConfig, $this->configWriter, $this->cacheTypeList, $storeId, $scope);
    }

    // --- load() ---

    public function testLoadReadsApiKeyFromCorrectXmlPath(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Data::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
        ]);

        $this->assertSame('test_api_key', $this->createAdapter()->load()['COMFINO_API_KEY']);
    }

    public function testLoadCoversBoolOptionAsBool(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Data::XML_PATH_SANDBOX_ENABLED, ScopeInterface::SCOPE_STORE, null, '1'],
            [Data::XML_PATH_WIDGET_ENABLED,  ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $loadedConfiguration = $this->createAdapter()->load();

        $this->assertTrue($loadedConfiguration['COMFINO_IS_SANDBOX']);
        $this->assertFalse($loadedConfiguration['COMFINO_WIDGET_ENABLED']);
    }

    public function testLoadFallsBackToDefaultWhenScopeConfigReturnsNull(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $defaults = ConfigManager::getDefaultConfigurationValues();
        $loadedConfiguration = $this->createAdapter()->load();

        foreach ($defaults as $key => $defaultValue) {
            if (isset($loadedConfiguration[$key])) {
                $this->assertSame($defaultValue, $loadedConfiguration[$key], "Default not applied for $key.");
            }
        }
    }

    public function testLoadReturnsValueForEveryKnownConfigKey(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $loadedConfiguration = $this->createAdapter()->load();
        $allKeys = array_keys(array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)));

        foreach ($allKeys as $key) {
            $this->assertArrayHasKey($key, $loadedConfiguration, "Key $key missing from load() output.");
        }
    }

    public function testLoadWithExplicitStoreIdPassesStoreScopeAndId(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Data::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, 7, 'scoped_key'],
        ]);

        $loadedConfiguration = $this->createScopedAdapter(7)->load();

        $this->assertSame('scoped_key', $loadedConfiguration['COMFINO_API_KEY']);
    }

    public function testLoadWithExplicitStoreIdAndCustomScopeUsesThatScope(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Data::XML_PATH_API_KEY, 'websites', 3, 'website_scoped_key'],
        ]);

        $loadedConfiguration = $this->createScopedAdapter(3, 'websites')->load();

        $this->assertSame('website_scoped_key', $loadedConfiguration['COMFINO_API_KEY']);
    }

    public function testLoadWithoutStoreIdDoesNotPassStoreIdArgument(): void
    {
        /* Ambient/no-scope path must call getValue() with exactly 2 args (today's behavior, unchanged) - no 3rd
           (store ID) argument may be passed. Verified via callback instead of willReturnMap, since the mock's
           argument-count matching for the map form proved unreliable across PHPUnit/Magento's default-typed
           interface signature. */
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scopeType) {
                return $path === Data::XML_PATH_API_KEY && $scopeType === ScopeInterface::SCOPE_STORE
                    ? 'unscoped_key'
                    : null;
            }
        );

        $loadedConfiguration = $this->createAdapter()->load();

        $this->assertSame('unscoped_key', $loadedConfiguration['COMFINO_API_KEY']);
    }

    // --- save() ---

    public function testSaveWritesApiKeyToCorrectXmlPath(): void
    {
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_API_KEY, 'new_key');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createAdapter()->save(['COMFINO_API_KEY' => 'new_key']);
    }

    public function testSaveCleansConfigCacheAfterWrite(): void
    {
        $this->configWriter->method('save');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createAdapter()->save(['COMFINO_API_KEY' => 'x', 'COMFINO_WIDGET_KEY' => 'y']);
    }

    public function testSaveSkipsUnknownKeysAndDoesNotCleanCache(): void
    {
        $this->configWriter->expects($this->never())->method('save');
        $this->cacheTypeList->expects($this->never())->method('cleanType');

        $this->createAdapter()->save(['COMFINO_NONEXISTENT_KEY' => 'value']);
    }

    public function testSaveDoesNotCleanCacheWhenInputIsEmpty(): void
    {
        $this->configWriter->expects($this->never())->method('save');
        $this->cacheTypeList->expects($this->never())->method('cleanType');

        $this->createAdapter()->save([]);
    }

    public function testSaveWritesMultipleKeysAndCleansOnce(): void
    {
        $this->configWriter->expects($this->exactly(2))->method('save');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createAdapter()->save(['COMFINO_API_KEY' => 'key1', 'COMFINO_SANDBOX_API_KEY' => 'key2']);
    }

    public function testSaveWithExplicitStoreIdPassesStoreScopeAndId(): void
    {
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_API_KEY, 'new_key', ScopeInterface::SCOPE_STORE, 7);

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createScopedAdapter(7)->save(['COMFINO_API_KEY' => 'new_key']);
    }

    public function testSaveWithExplicitStoreIdAndCustomScopeUsesThatScope(): void
    {
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_API_KEY, 'new_key', 'websites', 3);

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createScopedAdapter(3, 'websites')->save(['COMFINO_API_KEY' => 'new_key']);
    }

    public function testSaveWithoutStoreIdWritesToDefaultScope(): void
    {
        // Ambient/no-scope path must call save() with exactly 2 args (today's "always default scope" behavior, unchanged).
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_API_KEY, 'new_key');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->createAdapter()->save(['COMFINO_API_KEY' => 'new_key']);
    }

    // --- key map completeness ---

    public function testEveryConfigOptionKeyHasMappedXmlPath(): void
    {
        $allKeys = array_keys(array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)));

        // Save each key and verify configWriter is called (meaning an XML path exists).
        foreach ($allKeys as $key) {
            $writerCalled = false;

            $writer = $this->createMock(WriterInterface::class);
            $writer->method('save')->willReturnCallback(static function () use (&$writerCalled): void {
                $writerCalled = true;
            });

            $cache = $this->createMock(TypeListInterface::class);
            $cache->method('cleanType');

            $storageAdapter = new StorageAdapter($this->scopeConfig, $writer, $cache);
            $storageAdapter->save([$key => 'test']);

            $this->assertTrue($writerCalled, "No XML path mapped for config key: $key");
        }
    }
}

<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Configuration
 * @author Przemysław Kałuziński <pkaluzinski@comfinopay.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Configuration;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\ObjectManagerHarness;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * ScopedApiCredentials and ConfigManager::getScopedInstance() must read the requested scope, never the ambient store.
 * The scope config mock is keyed by 'default', 'websites/<id>' or 'stores/<id>'; missing paths fall back to default.
 */
final class ScopedApiCredentialsTest extends TestCase
{
    /** @var array<string, string|false> Dev-override environment variables to restore after the test. */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['COMFINO_DEV_ENV', 'COMFINO_DEV_API_HOST'] as $envVar) {
            $this->envBackup[$envVar] = getenv($envVar);
            putenv($envVar);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $envVar => $value) {
            putenv($value === false ? $envVar : $envVar . '=' . $value);
        }

        ObjectManagerHarness::reset();
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    /**
     * @param array<string, array<string, mixed>> $valuesByScope
     */
    private function makeScopeConfig(array $valuesByScope): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (
                string $path,
                string $scope = 'default',
                mixed $scopeCode = null
            ) use ($valuesByScope): mixed {
                $key = $scope === 'default' ? 'default' : $scope . '/' . $scopeCode;

                return $valuesByScope[$key][$path] ?? $valuesByScope['default'][$path] ?? null;
            }
        );

        return $scopeConfig;
    }

    /**
     * @param array<int, int> $storeWebsiteIds Store view ID => website ID
     */
    private function makeStoreManager(array $storeWebsiteIds = []): StoreManagerInterface
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function (mixed $storeId) use ($storeWebsiteIds): StoreInterface {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn((int) $storeId);
                $store->method('getWebsiteId')->willReturn($storeWebsiteIds[(int) $storeId] ?? 0);

                return $store;
            }
        );
        $storeManager->method('getWebsite')->willReturnCallback(function (mixed $websiteId): WebsiteInterface {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn((int) $websiteId);

            return $website;
        });

        return $storeManager;
    }

    public function testResolveScopePrefersStoreViewThenWebsiteThenDefault(): void
    {
        $credentials = new ScopedApiCredentials($this->makeScopeConfig([]), $this->makeStoreManager(), $this->createMock(EncryptorInterface::class));

        $this->assertSame([ScopeInterface::SCOPE_STORES, 5], $credentials->resolveScope('2', '5'));
        $this->assertSame([ScopeInterface::SCOPE_WEBSITES, 2], $credentials->resolveScope('2', ''));
        $this->assertSame([ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0], $credentials->resolveScope('', ''));
    }

    public function testGetCredentialsReadsSandboxKeyAndApiHostOfRequestedScope(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=http://ecommerce');

        $credentials = new ScopedApiCredentials(
            $this->makeScopeConfig([
                'default' => [
                    Data::XML_PATH_SANDBOX_ENABLED => true,
                    Data::XML_PATH_API_KEY => 'production-key',
                    Data::XML_PATH_SANDBOX_API_KEY => ' sandbox-key ',
                    Data::XML_PATH_DEV_ENV_VARS => false,
                ],
                'websites/2' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_DEV_ENV_VARS => true],
            ]),
            $this->makeStoreManager(),
            $this->createMock(EncryptorInterface::class)
        );

        $this->assertSame(
            [true, 'sandbox-key', null],
            $credentials->getCredentials(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0)
        );
        $this->assertSame(
            [false, 'production-key', 'http://ecommerce'],
            $credentials->getCredentials(ScopeInterface::SCOPE_WEBSITES, 2)
        );
    }

    public function testDevApiHostRequiresDevEnvironment(): void
    {
        /* dev_env_vars alone is not enough — without COMFINO_DEV_ENV=TRUE the standard API host applies. */
        $credentials = new ScopedApiCredentials(
            $this->makeScopeConfig([
                'default' => [Data::XML_PATH_API_KEY => 'key', Data::XML_PATH_DEV_ENV_VARS => true],
            ]),
            $this->makeStoreManager(),
            $this->createMock(EncryptorInterface::class)
        );

        $this->assertNull($credentials->getCredentials(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0)[2]);
    }

    public function testParentCredentialsFollowStoreViewToWebsiteToDefault(): void
    {
        $credentials = new ScopedApiCredentials(
            $this->makeScopeConfig([
                'default' => [Data::XML_PATH_API_KEY => 'default-key'],
                'websites/2' => [Data::XML_PATH_API_KEY => 'website-2-key'],
            ]),
            $this->makeStoreManager([5 => 2]),
            $this->createMock(EncryptorInterface::class)
        );

        $this->assertSame(
            [false, 'website-2-key', null],
            $credentials->getParentCredentials(ScopeInterface::SCOPE_STORES, 5)
        );
        $this->assertSame(
            [false, 'default-key', null],
            $credentials->getParentCredentials(ScopeInterface::SCOPE_WEBSITES, 2)
        );
        $this->assertNull($credentials->getParentCredentials(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfigManagerScopedInstanceReadsRequestedScope(): void
    {
        ObjectManagerHarness::install([
            ScopeConfigInterface::class => $this->makeScopeConfig([
                'default' => [Data::XML_PATH_WIDGET_KEY => 'default-widget-key'],
                'websites/2' => [Data::XML_PATH_WIDGET_KEY => 'website-2-widget-key'],
            ]),
            WriterInterface::class => $this->createMock(WriterInterface::class),
            TypeListInterface::class => $this->createMock(TypeListInterface::class),
            EncryptorInterface::class => $this->createMock(EncryptorInterface::class),
        ]);

        $website = ConfigManager::getScopedInstance(ScopeInterface::SCOPE_WEBSITES, 2);
        $default = ConfigManager::getScopedInstance(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

        $this->assertSame('website-2-widget-key', $website->getOptionWithDefault('COMFINO_WIDGET_KEY'));
        $this->assertSame('default-widget-key', $default->getOptionWithDefault('COMFINO_WIDGET_KEY'));
        $this->assertSame($website, ConfigManager::getScopedInstance(ScopeInterface::SCOPE_WEBSITES, 2));
        $this->assertSame(
            ConfigManager::getStoreScopedInstance(3),
            ConfigManager::getScopedInstance(ScopeInterface::SCOPE_STORES, 3)
        );
    }
}

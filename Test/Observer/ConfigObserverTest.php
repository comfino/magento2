<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Observer;

use Comfino\Api\Client;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Config\Backend\ReplacedApiKeys;
use Comfino\ComfinoGateway\Http\Psr17Factory;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\ComfinoGateway\Observer\ConfigObserver;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use Comfino\Tests\Support\LoggerHarnessTrait;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * ConfigObserver runs after the payment config is saved: it flushes the config cache and, when an API key is present,
 * validates it against the Comfino API and persists the returned widget key.
 *
 * It works on the saved scope (default / website / store view): the widget key is stored in that scope, or its override
 * is dropped when the scope resolves to its parent's credentials; the scopes below are re-synced the same way. API-reaching
 * tests use a real Comfino\Api\Client over a mocked PSR-18 transport.
 */
final class ConfigObserverTest extends TestCase
{
    use LoggerHarnessTrait;

    private WriterInterface&MockObject $configWriter;
    private TypeListInterface&MockObject $cacheTypeList;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private ManagerInterface&MockObject $messageManager;
    private StoreManagerInterface&MockObject $storeManager;

    private bool $storeManagerPrimed = false;

    /** @var list<array{path: string, apiKey: string, baseUrl: string}> Requests that reached the mocked transport. */
    private array $apiRequests = [];

    /** @var list<array{0: string, 1: string, 2: string, 3: int}> Recorded [action, value, scope, scope ID] writes. */
    private array $configWrites = [];

    /** @var array<string, string|false> Dev-override environment variables to restore after the test. */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install(['COMFINO_DEBUG' => true, 'COMFINO_SERVICE_MODE' => false]);
        $this->installLoggerHarness();

        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManagerPrimed = false;
        $this->apiRequests = [];
        $this->configWrites = [];

        /* The API host override is read from the environment; start every test without it (the dev container sets it)
           and let the host-related tests opt in through enableDevApiHost(). */
        foreach (['COMFINO_DEV_ENV', 'COMFINO_DEV_API_HOST'] as $envVar) {
            $this->envBackup[$envVar] = getenv($envVar);
            putenv($envVar);
        }
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, null);

        $this->resetLoggerHarness();

        ConfigManagerHarness::reset();

        foreach ($this->envBackup as $envVar => $value) {
            putenv($value === false ? $envVar : $envVar . '=' . $value);
        }

        parent::tearDown();
    }

    private function makeObserver(): ConfigObserver
    {
        if (!$this->storeManagerPrimed) {
            $this->primeStoreManager([]);
        }

        return new ConfigObserver(
            $this->configWriter,
            $this->cacheTypeList,
            $this->messageManager,
            $this->storeManager,
            new ScopedApiCredentials($this->scopeConfig, $this->storeManager, $this->createMock(EncryptorInterface::class))
        );
    }

    /**
     * Configures the default-scope config to report the given sandbox flag and API keys.
     */
    private function primeScopeConfig(bool $sandbox, string $productionKey, string $sandboxKey): void
    {
        $this->primeScopedConfig([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => $sandbox,
                Data::XML_PATH_API_KEY => $productionKey,
                Data::XML_PATH_SANDBOX_API_KEY => $sandboxKey,
            ],
        ]);
    }

    /**
     * Configures per-scope config values keyed by 'default', 'websites/<id>' or 'stores/<id>'. A path missing from
     * a website/store entry falls back to the default entry (enough inheritance for these tests).
     *
     * @param array<string, array<string, mixed>> $valuesByScope
     */
    private function primeScopedConfig(array $valuesByScope): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (
                string $path,
                string $scope = 'default',
                mixed $scopeCode = null
            ) use ($valuesByScope): mixed {
                $key = $scope === 'default' ? 'default' : $scope . '/' . $scopeCode;

                return $valuesByScope[$key][$path] ?? $valuesByScope['default'][$path] ?? null;
            }
        );
    }

    /**
     * Wires the store manager for the given store views (ID => website ID) and the websites they belong to, plus any extra website IDs.
     *
     * @param array<int, int> $storeWebsiteIds
     * @param list<int> $extraWebsiteIds
     */
    private function primeStoreManager(array $storeWebsiteIds, array $extraWebsiteIds = []): void
    {
        $this->storeManagerPrimed = true;

        $makeStore = function (int $storeId) use ($storeWebsiteIds): StoreInterface {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($storeWebsiteIds[$storeId]);

            return $store;
        };

        $makeWebsite = function (int $websiteId): WebsiteInterface {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);

            return $website;
        };

        $websiteIds = array_values(array_unique(array_merge($extraWebsiteIds, array_values($storeWebsiteIds))));
        sort($websiteIds);

        $this->storeManager->method('getStore')->willReturnCallback(
            static fn (mixed $storeId): StoreInterface => $makeStore((int) $storeId)
        );
        $this->storeManager->method('getWebsite')->willReturnCallback(
            static fn (mixed $websiteId): WebsiteInterface => $makeWebsite((int) $websiteId)
        );
        $this->storeManager->method('getStores')->willReturn(array_map($makeStore, array_keys($storeWebsiteIds)));
        $this->storeManager->method('getWebsites')->willReturn(array_map($makeWebsite, $websiteIds));
    }

    /**
     * Records every widget-key save/delete as [action, value, scope, scope ID] into $configWrites.
     */
    private function recordConfigWrites(): void
    {
        $this->configWriter->method('save')->willReturnCallback(
            function (string $path, mixed $value, string $scope = 'default', int $scopeId = 0): void {
                $this->assertSame(Data::XML_PATH_WIDGET_KEY, $path);
                $this->configWrites[] = ['save', (string) $value, $scope, $scopeId];
            }
        );
        $this->configWriter->method('delete')->willReturnCallback(
            function (string $path, string $scope = 'default', int $scopeId = 0): void {
                $this->assertSame(Data::XML_PATH_WIDGET_KEY, $path);
                $this->configWrites[] = ['delete', '', $scope, $scopeId];
            }
        );
    }

    /**
     * Turns the dev API host override on at the environment level, as in the local dev container.
     */
    private function enableDevApiHost(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=http://ecommerce');
    }

    /**
     * Seeds the ApiClient singleton with a real SDK client over a mocked PSR-18 transport that accepts every key
     * except the rejected ones (401) and answers the widget-key endpoint with "widget-key-for-<api key>" (or a
     * 500 for the keys in $widgetKeyFailures), recording each request's path, Api-Key and base URL.
     *
     * @param list<string> $rejectedKeys
     * @param list<string> $widgetKeyFailures
     */
    private function seedApiClient(array $rejectedKeys = [], array $widgetKeyFailures = []): Client
    {
        $psrClient = $this->createMock(PsrClientInterface::class);
        $psrClient->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use ($rejectedKeys, $widgetKeyFailures): Response {
                $apiKey = $request->getHeaderLine('Api-Key');
                $uri = $request->getUri();
                $path = $uri->getPath();

                $this->apiRequests[] = [
                    'path' => $path,
                    'apiKey' => $apiKey,
                    'baseUrl' => $uri->getScheme() . '://' . $uri->getAuthority(),
                ];

                if (in_array($apiKey, $rejectedKeys, true)) {
                    return new Response(
                        401,
                        ['Content-Type' => 'application/json'],
                        json_encode(['message' => 'Invalid credentials.'])
                    );
                }

                if (str_ends_with($path, 'widget-key') && in_array($apiKey, $widgetKeyFailures, true)) {
                    return new Response(
                        500,
                        ['Content-Type' => 'application/json'],
                        json_encode(['message' => 'Internal server error.'])
                    );
                }

                $body = str_ends_with($path, 'widget-key') ? json_encode('widget-key-for-' . $apiKey) : 'true';

                return new Response(200, ['Content-Type' => 'application/json'], $body);
            }
        );

        $factory = new Psr17Factory();
        $client = new Client($psrClient, $factory, $factory, 'seed-key');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, $client);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $psrClient);

        return $client;
    }

    /**
     * Builds an observer carrying the event data Magento\Config\Model\Config dispatches after a section save.
     */
    private function makeSaveEvent(string $website = '', string $store = ''): Observer
    {
        $observer = new Observer();
        $observer->setEvent(new Event(['website' => $website, 'store' => $store, 'changed_paths' => []]));

        return $observer;
    }

    /**
     * @return list<string> API keys sent in the Api-Key header, in request order
     */
    private function sentApiKeys(): array
    {
        return array_values(array_unique(array_column($this->apiRequests, 'apiKey')));
    }

    /**
     * @return array<string, list<string>> Base URLs each API key was sent to
     */
    private function baseUrlsByApiKey(): array
    {
        $baseUrls = [];

        foreach ($this->apiRequests as $request) {
            $baseUrls[$request['apiKey']][] = $request['baseUrl'];
        }

        return array_map(static fn (array $urls): array => array_values(array_unique($urls)), $baseUrls);
    }

    public function testAlwaysFlushesConfigCacheWhenNoApiKeyConfigured(): void
    {
        $this->primeScopeConfig(false, '', '');

        $this->configWriter->expects($this->never())->method('save');
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testSurfacesErrorAndStillFlushesCacheWhenClientCreationFails(): void
    {
        /* A non-empty key forces the API branch. The SDK Bootstrap is uninitialized, so ApiClient::getInstance()
           throws a LogicException, which the catch-all converts into an admin error message. */
        $this->primeScopeConfig(true, '', 'sandbox-key');

        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testTrimsApiKeyBeforeDecidingWhetherToValidate(): void
    {
        /* A whitespace-only key trims to empty, so no validation runs but the cache is still flushed. */
        $this->primeScopeConfig(false, "   \t", '');

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute(new Observer());
    }

    public function testReadsDefaultScopeWhenSavingDefaultConfig(): void
    {
        /* No website/store in the event means the default config was saved — read it there, not under the ambient store
           scope (which in the backend is the default store view). */
        $this->scopeConfig
            ->expects($this->atLeastOnce())
            ->method('getValue')
            ->with($this->anything(), ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null)
            ->willReturn('');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute($this->makeSaveEvent());
    }

    public function testSavesWidgetKeyIntoDefaultScopeWhenSavingDefaultConfig(): void
    {
        $this->primeScopeConfig(false, 'default-key', '');
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('delete');
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(
                Data::XML_PATH_WIDGET_KEY,
                'widget-key-for-default-key',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0
            );
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->makeObserver()->execute($this->makeSaveEvent());

        $this->assertSame(['default-key'], $this->sentApiKeys());
    }

    public function testWarnsAboutRejectedApiKeyOfSavedScopeAndKeepsItsWidgetKey(): void
    {
        $this->primeScopeConfig(false, 'rejected-key', '');
        $this->seedApiClient(['rejected-key']);

        $this->configWriter->expects($this->never())->method('save');
        $this->configWriter->expects($this->never())->method('delete');
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager
            ->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->callback(
                /* The rejected key itself must not be echoed into admin messages. */
                static fn (mixed $message): bool => (string) $message === 'API key is not valid.' && !str_contains((string) $message, 'rejected-key')
            ));
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute($this->makeSaveEvent());
    }

    /**
     * A newly entered key that the API rejects is replaced by the key saved before, so a typo does not stop payments.
     */
    public function testRestoresThePreviousKeyWhenTheNewOneIsRejected(): void
    {
        $this->primeScopeConfig(false, 'rejected-key', '');
        $this->seedApiClient(['rejected-key']);

        ReplacedApiKeys::remember(Data::XML_PATH_API_KEY, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0, '0:3:previous-encrypted');

        $this->configWriter->expects($this->once())->method('save')
            ->with(Data::XML_PATH_API_KEY, '0:3:previous-encrypted', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        $this->messageManager->expects($this->once())->method('addWarningMessage')
            ->with($this->callback(static fn (mixed $message): bool => (string) $message === 'API key is not valid. The previously saved key was kept.'));

        try {
            $this->makeObserver()->execute($this->makeSaveEvent());
        } finally {
            ReplacedApiKeys::reset();
        }
    }

    public function testSurfacesErrorWhenWidgetKeyOfSavedScopeCannotBeFetched(): void
    {
        /* The key is accepted, but fetching its widget key fails — the merchant saved this scope, so it is reported. */
        $this->primeScopeConfig(false, 'default-key', '');
        $this->seedApiClient([], ['default-key']);

        $this->configWriter->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->makeObserver()->execute($this->makeSaveEvent());
    }

    public function testValidatesWebsiteApiKeyAndSavesWidgetKeyIntoWebsiteScope(): void
    {
        /* Regression: a website with its own API key used to be validated with the default key and its widget key
           written to default config, so every website ended up with the default account's widget key. */
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
            'websites/2' => [Data::XML_PATH_API_KEY => 'website-2-key'],
        ]);
        $this->primeStoreManager([]);
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('delete');
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_WIDGET_KEY, 'widget-key-for-website-2-key', ScopeInterface::SCOPE_WEBSITES, 2);
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->makeObserver()->execute($this->makeSaveEvent(website: '2'));

        $this->assertSame(['website-2-key'], $this->sentApiKeys());
    }

    public function testValidatesStoreViewApiKeyAndSavesWidgetKeyIntoStoreScope(): void
    {
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
            'websites/2' => [Data::XML_PATH_API_KEY => 'website-2-key'],
            'stores/5' => [Data::XML_PATH_API_KEY => 'store-5-key'],
        ]);
        $this->primeStoreManager([5 => 2]);
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('delete');
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_WIDGET_KEY, 'widget-key-for-store-5-key', ScopeInterface::SCOPE_STORES, 5);

        $this->makeObserver()->execute($this->makeSaveEvent(store: '5'));

        $this->assertSame(['store-5-key'], $this->sentApiKeys());
    }

    public function testDropsWidgetKeyOverrideWhenWebsiteInheritsDefaultApiKey(): void
    {
        /* The website resolves to the default credentials, so the default widget key applies — any website-level widget
           key left from an earlier, different key must go instead of shadowing it. */
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
        ]);
        $this->primeStoreManager([]);
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('save');
        $this->configWriter
            ->expects($this->once())
            ->method('delete')
            ->with(Data::XML_PATH_WIDGET_KEY, ScopeInterface::SCOPE_WEBSITES, 2);
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->makeObserver()->execute($this->makeSaveEvent(website: '2'));

        /* The inherited key is still validated, but no widget key is fetched for it. */
        $this->assertSame(['default-key'], $this->sentApiKeys());
        $this->assertCount(0, array_filter(
            $this->apiRequests,
            static fn (array $request): bool => str_ends_with($request['path'], 'widget-key')
        ));
    }

    public function testDropsWidgetKeyOverrideWhenStoreViewInheritsWebsiteApiKey(): void
    {
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
            'websites/2' => [Data::XML_PATH_API_KEY => 'website-2-key'],
            'stores/5' => [Data::XML_PATH_API_KEY => 'website-2-key'],
        ]);
        $this->primeStoreManager([5 => 2]);
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('save');
        $this->configWriter
            ->expects($this->once())
            ->method('delete')
            ->with(Data::XML_PATH_WIDGET_KEY, ScopeInterface::SCOPE_STORES, 5);

        $this->makeObserver()->execute($this->makeSaveEvent(store: '5'));

        $this->assertSame(['website-2-key'], $this->sentApiKeys());
    }

    public function testTreatsSandboxSwitchAsDifferentCredentialsFromParentScope(): void
    {
        /* Same key strings, but the website runs in sandbox mode while default does not: the active key differs, so the
           website owns its own widget key. */
        $this->primeScopedConfig([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => false,
                Data::XML_PATH_API_KEY => 'production-key',
                Data::XML_PATH_SANDBOX_API_KEY => 'sandbox-key',
            ],
            'websites/2' => [Data::XML_PATH_SANDBOX_ENABLED => true],
        ]);
        $this->primeStoreManager([]);
        $this->seedApiClient();

        $this->configWriter->expects($this->never())->method('delete');
        $this->configWriter
            ->expects($this->once())
            ->method('save')
            ->with(Data::XML_PATH_WIDGET_KEY, 'widget-key-for-sandbox-key', ScopeInterface::SCOPE_WEBSITES, 2);

        $this->makeObserver()->execute($this->makeSaveEvent(website: '2'));

        $this->assertSame(['sandbox-key'], $this->sentApiKeys());
    }

    public function testValidatesAgainstApiHostOfSavedScopeAndTreatsItAsOwnCredentials(): void
    {
        /* Regression: the API host came from the ambient store's dev_env_vars, not from the saved scope's, so a key
           was validated against the wrong API. The website uses the same key as default but on the dev API host,
           which makes it a different account — it gets its own widget key instead of inheriting default's. */
        $this->enableDevApiHost();
        $this->primeScopedConfig([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => false,
                Data::XML_PATH_API_KEY => 'shared-key',
                Data::XML_PATH_DEV_ENV_VARS => false,
            ],
            'websites/2' => [Data::XML_PATH_DEV_ENV_VARS => true],
        ]);
        $this->seedApiClient();
        $this->recordConfigWrites();

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->makeObserver()->execute($this->makeSaveEvent(website: '2'));

        $this->assertSame(
            [['save', 'widget-key-for-shared-key', ScopeInterface::SCOPE_WEBSITES, 2]],
            $this->configWrites
        );
        $this->assertSame(['shared-key' => ['http://ecommerce']], $this->baseUrlsByApiKey());
    }

    public function testResetsApiHostOverrideLeftOnSharedClientWhenSavedScopeDoesNotUseIt(): void
    {
        /* The shared client may carry the dev API host applied for the ambient store (getInstance() only ever sets an
           override, never clears it); a scope without dev overrides must reach the standard API host. */
        $this->enableDevApiHost();
        $this->primeScopedConfig([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => true,
                Data::XML_PATH_SANDBOX_API_KEY => 'sandbox-key',
                Data::XML_PATH_DEV_ENV_VARS => false,
            ],
        ]);
        $this->seedApiClient()->setCustomApiBaseUrl('http://ecommerce');
        $this->recordConfigWrites();

        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->makeObserver()->execute($this->makeSaveEvent());

        $this->assertSame(['sandbox-key' => [Client::SANDBOX_API_BASE_URL]], $this->baseUrlsByApiKey());
        $this->assertSame([['save', 'widget-key-for-sandbox-key', 'default', 0]], $this->configWrites);
    }

    public function testResyncsWidgetKeysOfAllScopesBelowSavedDefaultConfig(): void
    {
        /* Regression: switching default config to another account left a website with its own credentials on the new
           default account's widget key. Website 2 and its store view use their own account on the dev API host. */
        $this->enableDevApiHost();
        $website2 = [
            Data::XML_PATH_SANDBOX_ENABLED => false,
            Data::XML_PATH_API_KEY => 'website-2-key',
            Data::XML_PATH_DEV_ENV_VARS => true,
        ];
        $this->primeScopedConfig([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => true,
                Data::XML_PATH_SANDBOX_API_KEY => 'sandbox-key',
                Data::XML_PATH_DEV_ENV_VARS => false,
            ],
            'websites/2' => $website2,
            'stores/2' => $website2,
        ]);
        $this->primeStoreManager([1 => 1, 2 => 2]);
        $this->seedApiClient();
        $this->recordConfigWrites();

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->makeObserver()->execute($this->makeSaveEvent());

        $this->assertSame(
            [
                ['save', 'widget-key-for-sandbox-key', 'default', 0],
                ['delete', '', ScopeInterface::SCOPE_WEBSITES, 1],
                ['save', 'widget-key-for-website-2-key', ScopeInterface::SCOPE_WEBSITES, 2],
                ['delete', '', ScopeInterface::SCOPE_STORES, 1],
                ['delete', '', ScopeInterface::SCOPE_STORES, 2],
            ],
            $this->configWrites
        );
        $this->assertSame(
            [
                'sandbox-key' => [Client::SANDBOX_API_BASE_URL],
                'website-2-key' => ['http://ecommerce'],
            ],
            $this->baseUrlsByApiKey()
        );
    }

    public function testResyncsOnlyStoreViewsOfSavedWebsite(): void
    {
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
            'websites/1' => [Data::XML_PATH_API_KEY => 'website-1-key'],
            'stores/1' => [Data::XML_PATH_API_KEY => 'store-1-key'],
            'stores/2' => [Data::XML_PATH_API_KEY => 'store-2-key'],
        ]);
        $this->primeStoreManager([1 => 1, 2 => 2]);
        $this->seedApiClient();
        $this->recordConfigWrites();

        $this->makeObserver()->execute($this->makeSaveEvent(website: '1'));

        // Store view 2 belongs to website 2, so saving website 1 leaves it alone.
        $this->assertSame(
            [
                ['save', 'widget-key-for-website-1-key', ScopeInterface::SCOPE_WEBSITES, 1],
                ['save', 'widget-key-for-store-1-key', ScopeInterface::SCOPE_STORES, 1],
            ],
            $this->configWrites
        );
    }

    public function testSilentlySkipsFailedChildScopeAndKeepsSyncingOthers(): void
    {
        // A child scope the merchant didn't save must not raise an admin message; its error is only logged.
        $this->primeScopedConfig([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
            'websites/1' => [Data::XML_PATH_API_KEY => 'website-1-key'],
            'websites/2' => [Data::XML_PATH_API_KEY => 'rejected-key'],
        ]);
        $this->primeStoreManager([], [1, 2]);
        $this->seedApiClient(['rejected-key']);
        $this->recordConfigWrites();

        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->makeObserver()->execute($this->makeSaveEvent());

        $this->assertSame(
            [
                ['save', 'widget-key-for-default-key', 'default', 0],
                ['save', 'widget-key-for-website-1-key', ScopeInterface::SCOPE_WEBSITES, 1],
            ],
            $this->configWrites
        );
    }

    public function testFlushesFullPageCacheWhenProductCategoryFiltersChanged(): void
    {
        /* A stale full_page-cached product page bakes the widget config (incl. category-filtered offer types) into its
           markup, so changing this path must also bust full_page — not just config. */
        $this->primeScopeConfig(false, '', '');

        $this->cacheTypeList
            ->expects($this->exactly(2))
            ->method('cleanType')
            ->with($this->logicalOr('config', 'full_page'));

        $observer = new Observer();
        $observer->setEvent(new Event([
            'changed_paths' => [Data::XML_PATH_PRODUCT_CATEGORY_FILTERS],
        ]));

        $this->makeObserver()->execute($observer);
    }

    public function testDoesNotFlushFullPageCacheWhenUnrelatedPathChanged(): void
    {
        $this->primeScopeConfig(false, '', '');

        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $observer = new Observer();
        $observer->setEvent(new Event([
            'changed_paths' => [Data::XML_PATH_PAYMENT_TEXT],
        ]));

        $this->makeObserver()->execute($observer);
    }
}
